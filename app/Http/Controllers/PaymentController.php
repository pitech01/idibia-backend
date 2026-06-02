<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        
        // 🛡️ PROACTIVE VERIFICATION (Safety Net)
        // Check for any pending Paystack payments and try to verify them.
        // This handles cases where the user closed the browser too fast 
        // OR the webhook didn't reach the server (common in local development).
        $pendingPayments = $user->payments()
            ->where('status', 'pending')
            ->where('method', 'paystack')
            ->where('created_at', '>=', now()->subHours(24))
            ->get();

        foreach ($pendingPayments as $payment) {
            try {
                $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()->withHeaders([
                    'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
                ])->get(config('services.paystack.payment_url') . "/transaction/verify/{$payment->reference}");

                if ($response->successful() && $response->json()['data']['status'] === 'success') {
                    $this->processSuccessfulPayment($payment, $response->json()['data']);
                }
            } catch (\Exception $e) {
                \Illuminate\Support\Facades\Log::error("Proactive verification failed for {$payment->reference}: " . $e->getMessage());
            }
        }

        // Return refreshed list
        return $user->payments()->latest()->get();
    }

    public function initialize(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:100',
            'email' => 'required|email',
        ]);

        $amount = $request->amount * 100; // Paystack takes amount in kobo
        $email = $request->email;
        $reference = \Illuminate\Support\Str::uuid(); // Generate unique ref

        // Create pending payment record
        $payment = $request->user()->payments()->create([
            'amount' => $request->amount,
            'reference' => $reference,
            'status' => 'pending',
            'type' => 'credit', // Top-up
            'method' => 'paystack',
            'description' => 'Wallet Top Up',
            'user_id' => $request->user()->id
        ]);

        $channels = ['card', 'bank', 'ussd', 'qr', 'mobile_money', 'bank_transfer'];
        if ($request->method === 'card') $channels = ['card'];
        if ($request->method === 'bank') $channels = ['bank', 'bank_transfer'];
        if ($request->method === 'ussd') $channels = ['ussd'];

        // Call Paystack API
        $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.paystack.payment_url') . '/transaction/initialize', [
            'email' => $email,
            'amount' => $amount,
            'reference' => $reference,
            'channels' => $channels,
            'callback_url' => config('app.frontend_url') . '/patient/dashboard', // Redirect to dashboard to trigger verification
        ]);

        if ($response->successful()) {
            $data = $response->json()['data'];
            $data['public_key'] = config('services.paystack.public_key');
            return response()->json($data);
        }

        return response()->json(['message' => 'Payment initialization failed'], 400);
    }

    public function verify(Request $request)
    {
        $reference = $request->reference;
        $payment = \App\Models\Payment::with(['user.patient', 'user.doctor'])->where('reference', $reference)->first();

        if (!$payment) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Transaction already verified', 'data' => $payment]);
        }

        // Verify with Paystack
        $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
        ])->get(config('services.paystack.payment_url') . "/transaction/verify/{$reference}");

        if ($response->successful() && $response->json()['data']['status'] === 'success') {
            return $this->processSuccessfulPayment($payment, $response->json()['data']);
        }

        $payment->update(['status' => 'failed']);
        return response()->json(['message' => 'Payment verification failed'], 400);
    }
    public function payForAppointment(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id'
        ]);

        $user = $request->user();
        $appointment = \App\Models\Appointment::findOrFail($request->appointment_id);

        if ((int) $appointment->patient_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($appointment->payment_status === 'paid') {
            return response()->json(['message' => 'Appointment already paid'], 400);
        }

        $patient = $user->patient;
        if (!$patient || $patient->wallet_balance < $appointment->amount) {
            return response()->json(['message' => 'Insufficient wallet balance'], 400);
        }

        // Process Payment
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $patient->wallet_balance -= $appointment->amount;
            $patient->save();

            $appointment->update([
                'payment_status' => 'paid',
                'status' => 'confirmed'
            ]);

            // Create Transaction Record
            $user->payments()->create([
                'amount' => $appointment->amount,
                'appointment_id' => $appointment->id,
                'doctor_id' => $appointment->doctor_id,
                'reference' => 'APP-WAL-' . $appointment->id . '-' . time(),
                'status' => 'success',
                'paid_at' => now(),
                'type' => 'debit',
                'method' => 'wallet',
                'description' => 'Consultation Payment - DR. ' . $appointment->doctor->name,
                'user_id' => $user->id
            ]);

            \Illuminate\Support\Facades\DB::commit();

            // After commit, earnings will be distributed on completion
            // $this->distributeEarnings($appointment);

            return response()->json(['message' => 'Payment successful', 'appointment' => $appointment]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Payment failed: ' . $e->getMessage()], 500);
        }
    }



    public function initializePaystack(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'use_wallet' => 'boolean'
        ]);

        $user = $request->user();
        $appointment = \App\Models\Appointment::with('doctor')->findOrFail($request->appointment_id);
        
        if ($appointment->payment_status === 'paid') {
            return response()->json(['message' => 'Already paid'], 400);
        }

        $amountToCharge = $appointment->amount;
        $walletAmountUsed = 0;

        if ($request->use_wallet && $user->patient && $user->patient->wallet_balance > 0) {
            $walletBalance = (float) $user->patient->wallet_balance;
            $appointmentAmount = (float) $appointment->amount;

            if ($walletBalance >= $appointmentAmount) {
                // If they have enough, but we want to still allow card payment,
                // we only force wallet if they didn't specifically choose Paystack.
                // But here they DID call initializePaystack, so maybe they want to pay with card?
                // For now, let's just make it so if they have enough, we can still charge card
                // if use_wallet is true, it means they WANT to use wallet. 
                // If they want to pay 0 via card, that's invalid for Paystack.
                $walletAmountUsed = $appointmentAmount - 0.1; // Leave a tiny bit for Paystack (min 100 kobo/1 naira)
                // Actually Paystack minimum is usually higher. Let's say 50 naira.
                if ($appointmentAmount > 50) {
                    $walletAmountUsed = $appointmentAmount - 50;
                    $amountToCharge = 50;
                } else {
                    $walletAmountUsed = 0;
                    $amountToCharge = $appointmentAmount;
                }
            } else {
                $walletAmountUsed = $walletBalance;
                $amountToCharge = $appointmentAmount - $walletBalance;
            }
        }

        $email = $user->email;
        if ($amountToCharge < 0.01) {
            return response()->json(['message' => 'Invalid payment amount. Consultation fee must be set.'], 400);
        }
        $amountKobo = (int) ($amountToCharge * 100);
        $reference = 'DIBIA-' . $appointment->id . '-' . uniqid();

        // Create a local payment record (pending)
        \App\Models\Payment::create([
            'user_id' => $user->id,
            'appointment_id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'reference' => $reference,
            'amount' => $amountToCharge,
            'status' => 'pending',
            'type' => 'credit', // Money coming INTO the system from Card
            'method' => 'paystack',
            'description' => "Card Payment for Consultation - Dr. " . $appointment->doctor->name
        ]);

        // Paystack Initialize
        $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
            'Content-Type' => 'application/json',
        ])->post(config('services.paystack.payment_url') . '/transaction/initialize', [
            'email' => $email,
            'amount' => $amountKobo,
            'reference' => $reference,
            'callback_url' => config('app.frontend_url') . '/patient/dashboard?tab=appointments&verify=' . $reference,
            'metadata' => [
                'appointment_id' => $appointment->id,
                'wallet_amount_used' => $walletAmountUsed,
                'type' => 'appointment_booking'
            ]
        ]);

        if ($response->successful()) {
            $data = $response->json()['data'];
            $data['public_key'] = config('services.paystack.public_key');
            $data['amount'] = $amountToCharge;
            $data['reference'] = $reference;
            return response()->json($data);
        }

        return response()->json([
            'message' => 'Paystack initialization failed',
            'error' => $response->json(),
            'status' => $response->status()
        ], 400);
    }

    public function verifyPaystack(Request $request)
    {
        $reference = $request->reference ?? $request->query('reference') ?? $request->input('reference');
        
        if (!$reference) return response()->json(['message' => 'Reference is required'], 400);

        // 1. Check local record first
        $payment = \App\Models\Payment::with(['user.patient', 'user.doctor'])->where('reference', $reference)->first();
        if (!$payment) return response()->json(['message' => 'Payment record not found'], 404);

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Payment already verified', 'status' => 'success']);
        }

        // 2. Verify with Paystack
        $response = \Illuminate\Support\Facades\Http::timeout(30)->withoutVerifying()->withHeaders([
            'Authorization' => 'Bearer ' . config('services.paystack.secret_key'),
        ])->get(config('services.paystack.payment_url') . "/transaction/verify/{$reference}");

        if ($response->successful() && $response->json()['data']['status'] === 'success') {
            return $this->processSuccessfulPayment($payment, $response->json()['data']);
        }

        return response()->json(['message' => 'Payment verification failed', 'status' => 'failed', 'error' => $response->json()], 400);
    }

    public function handleWebhook(Request $request)
    {
        \Illuminate\Support\Facades\Log::info('Paystack Webhook Received', ['payload' => $request->all()]);
        
        $signature = $request->header('x-paystack-signature');
        $secret = config('services.paystack.secret_key');

        if (!$signature || $signature !== hash_hmac('sha512', $request->getContent(), $secret)) {
            \Illuminate\Support\Facades\Log::warning('Paystack Webhook Signature Mismatch');
            abort(401);
        }

        $event = $request->event;
        $data = $request->data;

        \Illuminate\Support\Facades\Log::info('Paystack Webhook Event: ' . $event);

        if ($event === 'charge.success') {
            $reference = $data['reference'];
            \Illuminate\Support\Facades\Log::info('Paystack Webhook Success for Reference: ' . $reference);
            
            $payment = \App\Models\Payment::with(['user.patient', 'user.doctor'])->where('reference', $reference)->first();

            if (!$payment) {
                \Illuminate\Support\Facades\Log::warning('Payment record not found for reference: ' . $reference);
            } elseif ($payment->status === 'success') {
                \Illuminate\Support\Facades\Log::info('Payment already marked as success: ' . $reference);
            } else {
                $this->processSuccessfulPayment($payment, $data);
            }
        }

        return response()->json(['status' => 'success']);
    }

    private function processSuccessfulPayment($payment, $paystackData)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            \Illuminate\Support\Facades\Log::info('Processing successful payment for reference: ' . $payment->reference);

            // 🔒 LOCK: Only proceed if we can successfully change status from pending/failed to success
            // This prevents race conditions between Webhook and Manual Verification
            $updated = \App\Models\Payment::where('id', $payment->id)
                ->where('status', '!=', 'success')
                ->update([
                    'status' => 'success',
                    'paid_at' => now()
                ]);

            if (!$updated) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json(['message' => 'Payment already processed', 'status' => 'success']);
            }

            // Refresh the payment model to reflect the new status for subsequent logic
            $payment->refresh();

            // Save Payment Method if authorization exists
            if (isset($paystackData['authorization'])) {
                $auth = $paystackData['authorization'];
                
                // Check if this card already exists for the user
                $existing = \App\Models\PaymentMethod::where('user_id', $payment->user_id)
                    ->where('last4', $auth['last4'] ?? null)
                    ->where('brand', $auth['brand'] ?? null)
                    ->first();

                if (!$existing) {
                    \App\Models\PaymentMethod::create([
                        'user_id' => $payment->user_id,
                        'method_type' => 'card',
                        'provider' => 'paystack',
                        'last4' => $auth['last4'] ?? null,
                        'brand' => $auth['brand'] ?? null,
                        'exp_month' => $auth['exp_month'] ?? null,
                        'exp_year' => $auth['exp_year'] ?? null,
                        'authorization_code' => $auth['authorization_code'] ?? null,
                        'is_default' => $payment->user->paymentMethods()->count() === 0
                    ]);
                }
            }

            if ($payment->appointment_id) {
                $appointment = \App\Models\Appointment::find($payment->appointment_id);
                if ($appointment) {
                    \Illuminate\Support\Facades\Log::info('Updating appointment ID:' . $appointment->id . ' to confirmed status.');
                    $appointment->update([
                        'payment_status' => 'paid',
                        'status' => 'confirmed'
                    ]);
                    
                    \Illuminate\Support\Facades\Log::info('Appointment ' . $appointment->id . ' status after update: ' . $appointment->status);

                    // UPDATE: Unified Balance Logic
                    // 1. Credit the wallet with the amount paid via Card (Net balance + Card Part)
                    $patient = $payment->user->patient;
                    if ($patient) {
                        $patient->increment('wallet_balance', $payment->amount);
                        
                        // 2. Immediately Debit the FULL appointment fee from the wallet
                        $totalFee = $appointment->amount;
                        $patient->decrement('wallet_balance', $totalFee);

                        // 3. Create the standard Consultation Payment debit record for history
                        \App\Models\Payment::create([
                            'user_id' => $payment->user_id,
                            'appointment_id' => $payment->appointment_id,
                            'doctor_id' => $appointment->doctor_id,
                            'reference' => 'APP-PAY-' . $appointment->id . '-' . time(),
                            'amount' => $totalFee,
                            'status' => 'success',
                            'paid_at' => now(),
                            'type' => 'debit',
                            'method' => 'wallet',
                            'description' => 'Consultation Payment - Dr. ' . $appointment->doctor->name
                        ]);
                    }
                } else {
                    \Illuminate\Support\Facades\Log::warning('Appointment with ID ' . $payment->appointment_id . ' not found for payment ' . $payment->id);
                }
            } else {
                // Wallet top up
                \Illuminate\Support\Facades\Log::info('Processing wallet top-up for user: ' . $payment->user_id . ' (Role: ' . $payment->user->role . ')');
                
                $user = $payment->user;
                if ($user->role === 'patient' && $user->patient) {
                    $user->patient->increment('wallet_balance', $payment->amount);
                } elseif ($user->role === 'doctor' && $user->doctor) {
                    $user->doctor->increment('wallet_balance', $payment->amount);
                } else {
                    // Admin or fallback to user table
                    $user->increment('wallet_balance', $payment->amount);
                }
            }

            \Illuminate\Support\Facades\DB::commit();
            \Illuminate\Support\Facades\Log::info('Payment processing completed successfully for reference: ' . $payment->reference);

            // 🚀 REAL-TIME NOTIFICATION: Notify the user via signaling server
            $this->broadcastToUser($payment->user_id, 'payment:success', [
                'reference' => $payment->reference,
                'amount' => $payment->amount,
                'status' => 'success',
                'description' => $payment->description
            ]);

            return response()->json(['message' => 'Payment successful', 'status' => 'success']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            \Illuminate\Support\Facades\Log::error('Payment processing failed for reference ' . $payment->reference . ': ' . $e->getMessage());
            return response()->json(['message' => 'Internal processing error: ' . $e->getMessage()], 500);
        }
    }

    public function getPaymentMethods(Request $request)
    {
        return $request->user()->paymentMethods()->latest()->get();
    }

    public function deletePaymentMethod(Request $request, $id)
    {
        $method = $request->user()->paymentMethods()->findOrFail($id);
        $method->delete();
        return response()->json(['message' => 'Payment method removed']);
    }

    public function setDefaultPaymentMethod(Request $request, $id)
    {
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $request->user()->paymentMethods()->update(['is_default' => false]);
            $method = $request->user()->paymentMethods()->findOrFail($id);
            $method->update(['is_default' => true]);
            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['message' => 'Default payment method updated']);
        } catch (\Exception $e) {
            return response()->json(['message' => 'Failed to update default method'], 500);
        }
    }

    private function broadcastToUser($userId, $event, $data)
    {
        // Add receiver_id to data for targeting in the signaling server
        $data['receiver_id'] = $userId;
        $this->broadcastToSignaling($event, $data);
    }

    private function broadcastToSignaling($event, $data)
    {
        $port = env('SIGNALING_PORT', 3000);
        $url = "http://localhost:{$port}/broadcast";
        
        try {
            \Illuminate\Support\Facades\Http::timeout(2)
                ->withoutVerifying()
                ->post($url, [
                    'event' => $event,
                    'data' => $data
                ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Signaling broadcast failed: " . $e->getMessage());
        }
    }
}
