<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function index(Request $request)
    {
        // Return latest payments first
        return $request->user()->payments()->latest()->get();
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

        // Call Paystack API
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
            'Content-Type' => 'application/json',
        ])->post('https://api.paystack.co/transaction/initialize', [
            'email' => $email,
            'amount' => $amount,
            'reference' => $reference,
            'callback_url' => env('VITE_API_BASE_URL') . '/payments/callback', // We handle callback in frontend actually usually or verify endpoint
            // But usually we just return auth url to frontend
        ]);

        if ($response->successful()) {
            return response()->json($response->json()['data']);
        }

        return response()->json(['message' => 'Payment initialization failed'], 400);
    }

    public function verify(Request $request)
    {
        $reference = $request->reference;
        $payment = \App\Models\Payment::where('reference', $reference)->first();

        if (!$payment) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($payment->status === 'success') {
            return response()->json(['message' => 'Transaction already verified', 'data' => $payment]);
        }

        // Verify with Paystack
        $response = \Illuminate\Support\Facades\Http::withHeaders([
            'Authorization' => 'Bearer ' . env('PAYSTACK_SECRET_KEY'),
        ])->get("https://api.paystack.co/transaction/verify/{$reference}");

        if ($response->successful() && $response->json()['data']['status'] === 'success') {
            // Update Payment Status
            $payment->update(['status' => 'success']);

            // Update User Wallet
            $patient = $payment->user->patient;
            if ($patient) {
                $patient->wallet_balance += $payment->amount;
                $patient->save();
            }

            return response()->json(['message' => 'Payment successful', 'data' => $payment]);
        }

        $payment->update(['status' => 'failed']);
        return response()->json(['message' => 'Payment verification failed'], 400);
    }
}
