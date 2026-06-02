<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        // Automatically ensure signaling is up for communication features
        $this->ensureSignalingIsRunning();

        $user = $request->user();
        if ($user->role === 'patient') {
            $appointments = $user->appointments()->with('doctor.doctor')
                ->latest()
                ->get()
                ->map(function($appt) {
                    return [
                        'id' => $appt->id,
                        'doctor_id' => $appt->doctor_id,
                        'doctor' => $appt->doctor,
                        'patient_id' => $appt->patient_id,
                        'appointment_date' => $appt->appointment_date,
                        'start_time' => $appt->start_time,
                        'end_time' => $appt->end_time,
                        'duration' => $appt->duration ?? 30,
                        'status' => $appt->status,
                        'payment_status' => $appt->payment_status,
                        'amount' => $appt->amount,
                        'type' => $appt->type,
                        'meeting_link' => $appt->meeting_link,
                        'reason' => $appt->reason,
                        'iso_start_time' => \Carbon\Carbon::parse($appt->appointment_date->format('Y-m-d') . ' ' . $appt->start_time)->toIso8601String(),
                    ];
                });
        } else {
             $appointments = \App\Models\Appointment::with(['patient'])
                ->where('doctor_id', $user->id)
                ->orderBy('appointment_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get()
                ->map(function($appt) {
                    return [
                        'id' => $appt->id,
                        'doctor_id' => $appt->doctor_id,
                        'patient' => $appt->patient,
                        'patient_id' => $appt->patient_id,
                        'appointment_date' => $appt->appointment_date,
                        'start_time' => $appt->start_time,
                        'end_time' => $appt->end_time,
                        'duration' => $appt->duration ?? 30,
                        'status' => $appt->status,
                        'payment_status' => $appt->payment_status,
                        'amount' => $appt->amount,
                        'type' => $appt->type,
                        'meeting_link' => $appt->meeting_link,
                        'reason' => $appt->reason,
                        'iso_start_time' => \Carbon\Carbon::parse($appt->appointment_date->format('Y-m-d') . ' ' . $appt->start_time)->toIso8601String(),
                    ];
                });
        }

        return response()->json($appointments);
    }

    public function getDoctorSlots(Request $request, $id)
    {
        $request->validate(['date' => 'required|date']);
        $date = $request->date;
        $dayName = date('l', strtotime($date));

        // Get Doctor Profile & Settings
        $doctorUser = \App\Models\User::with('doctor')->findOrFail($id);
        
        // 🔒 CRITICAL: Only allow bookings for ACTIVE doctors
        if (!$doctorUser->doctor || $doctorUser->status !== 'active' || $doctorUser->doctor->status !== 'active') {
            return response()->json(['message' => 'Doctor is currently unavailable for bookings.', 'slots' => []], 403);
        }

        $doctorProfile = $doctorUser->doctor;
        $consultationDuration = (int) ($doctorProfile->consultation_duration ?? 30); // Minutes
        if ($consultationDuration <= 5) $consultationDuration = 30; // Safety floor
        $minNoticeMinutes = 30; // Minimum booking notice

        // Get Availability for the day
        $availability = \App\Models\DoctorAvailability::where('doctor_id', $doctorProfile->id)
            ->where('day', $dayName)
            ->first();

        if (!$availability || !$availability->is_available) {
            return response()->json(['slots' => [], 'duration' => $consultationDuration]);
        }

        // Parse Times with Carbon (Server Time Authority)
        // Ensure we handle potential nulls or malformed times
        try {
            $availStart = \Carbon\Carbon::parse($date . ' ' . $availability->start_time);
            $availEnd = \Carbon\Carbon::parse($date . ' ' . $availability->end_time);
        } catch (\Exception $e) {
            return response()->json(['slots' => [], 'error' => 'Invalid availability configuration'], 422);
        }

        $serverNow = \Carbon\Carbon::now();

        // Get existing appointments to check overlaps
        $bookedAppointments = \App\Models\Appointment::where('doctor_id', $id)
            ->where('appointment_date', $date)
            ->whereIn('status', ['pending_payment', 'confirmed', 'ongoing']) 
            ->get();

        $slots = [];
        $currentSlot = $availStart->copy();

        // Standardize: If the shift start is slightly off, we still generate from the start
        // But we ensure we don't exceed the end time
        while ($currentSlot->copy()->addMinutes($consultationDuration)->lte($availEnd)) {
            $slotStart = $currentSlot->copy();
            $slotEnd = $slotStart->copy()->addMinutes($consultationDuration);

            // RULE 1: Minimum Booking Notice & Past Time Check
            if ($slotStart->lt($serverNow->copy()->addMinutes($minNoticeMinutes))) {
                $currentSlot->addMinutes($consultationDuration);
                continue;
            }

            // RULE 2: Overlap Detection
            $isBooked = false;
            foreach ($bookedAppointments as $appt) {
                if (!$appt->start_time || !$appt->end_time) continue;
                
                $apptStart = \Carbon\Carbon::parse($date . ' ' . $appt->start_time);
                $apptEnd = \Carbon\Carbon::parse($date . ' ' . $appt->end_time);

                // Overlap Condition: (StartA < EndB) && (EndA > StartB)
                if ($slotStart->lt($apptEnd) && $slotEnd->gt($apptStart)) {
                    $isBooked = true;
                    break;
                }
            }

            if (!$isBooked) {
                $slots[] = $slotStart->format('H:i');
            }

            // Move to next slot
            $currentSlot->addMinutes($consultationDuration);
        }

        return response()->json([
            'slots' => $slots,
            'duration' => $consultationDuration
        ]);
    }
    
     public function getAvailableSlots(Request $request, $doctorId)
    {
        $date = $request->query('date');
        if (!$date) return response()->json(['message' => 'Date is required'], 400);

        $dayOfWeek = date('l', strtotime($date));
        $availability = \App\Models\Availability::where('doctor_id', $doctorId)
            ->where('day', $dayOfWeek)
            ->where('is_available', true)
            ->first();

        if (!$availability) {
            return response()->json(['slots' => []]);
        }

        $doctor = \App\Models\Doctor::where('user_id', $doctorId)->first();
        $duration = $doctor->consultation_duration ?? 30;

        $startTime = strtotime($availability->start_time);
        $endTime = strtotime($availability->end_time);
        
        $isToday = $date === date('Y-m-d');
        // Use Africa/Lagos timezone as per .env
        $now = now('Africa/Lagos')->timestamp;

        $existingAppointments = \App\Models\Appointment::where('doctor_id', $doctorId)
            ->where('appointment_date', $date)
            ->where('status', '!=', 'cancelled')
            ->pluck('start_time')
            ->map(fn($time) => date('H:i', strtotime($time)))
            ->toArray();

        $slots = [];
        $current = $startTime;

        while ($current + ($duration * 60) <= $endTime) {
            $slotTime = date('H:i', $current);
            
            // Check if slot is in the past if it's today
            // We need to compare full timestamp for accuracy
            $slotTimestamp = strtotime($date . ' ' . $slotTime);

            if ($isToday && $slotTimestamp <= $now) {
                $current += ($duration * 60);
                continue;
            }

            if (!in_array($slotTime, $existingAppointments)) {
                $slots[] = $slotTime;
            }
            $current += ($duration * 60);
        }

        return response()->json(['slots' => $slots]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|date',
            'start_time' => 'required', // HH:mm format
            'reason' => 'nullable|string',
            'type' => 'required|in:video,in-person'
        ]);

        $doctorUser = \App\Models\User::with('doctor')->findOrFail($request->doctor_id);
        
        // 🔒 CRITICAL: Only allow bookings for ACTIVE doctors
        if (!$doctorUser->doctor || $doctorUser->status !== 'active' || $doctorUser->doctor->status !== 'active') {
             return response()->json(['message' => 'This doctor is currently unavailable for new bookings.'], 403);
        }

        // Settings
        $consultationDuration = (int) ($doctorUser->doctor->consultation_duration ?? 30);
        if ($consultationDuration <= 0) $consultationDuration = 30;
        $minNoticeMinutes = 30;
        
        // Calculate end_time based on doctor's consultation duration
        $doctor = \App\Models\Doctor::where('user_id', $request->doctor_id)->first();
        $duration = $doctor->consultation_duration ?? 30;
        $endTime = date('H:i:s', strtotime($request->start_time) + ($duration * 60));

        // Times
        $date = $request->appointment_date;
        $startTime = $request->start_time;
        
        $slotStart = \Carbon\Carbon::parse($date . ' ' . $startTime);
        $slotEnd = $slotStart->copy()->addMinutes((int) $consultationDuration);
        $serverNow = \Carbon\Carbon::now();

        // 1. Availability Check (Day & Working Hours)
        $dayName = $slotStart->format('l');
        $availability = \App\Models\DoctorAvailability::where('doctor_id', $doctorUser->doctor->id)
            ->where('day', $dayName)
            ->first();

        if (!$availability || !$availability->is_available) {
            return response()->json(['message' => 'Doctor is not available on this day'], 422);
        }

        $availStart = \Carbon\Carbon::parse($date . ' ' . $availability->start_time);
        $availEnd = \Carbon\Carbon::parse($date . ' ' . $availability->end_time);

        if ($slotStart->lt($availStart) || $slotEnd->gt($availEnd)) {
             return response()->json(['message' => 'Selected time is outside working hours'], 422);
        }

        // 2. Minimum Booking Notice (30 MIN RULE)
        if ($slotStart->lt($serverNow->copy()->addMinutes((int) $minNoticeMinutes))) {
             return response()->json(['message' => 'Bookings must be made at least 30 minutes in advance.'], 422);
        }

        // 3. Double Booking / Overlap / Lock Check
        // Use Transaction & LockForUpdate for Race Condition Protection
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            // Check for ANY overlap with non-cancelled appointments
            $exists = \App\Models\Appointment::where('doctor_id', $request->doctor_id)
                ->where('appointment_date', $date)
                ->whereIn('status', ['pending_payment', 'confirmed', 'ongoing']) // Check locked slots too
                ->where(function ($query) use ($slotStart, $slotEnd) {
                    $query->where('start_time', '<', $slotEnd->format('H:i:s'))
                          ->where('end_time', '>', $slotStart->format('H:i:s'));
                })
                ->lockForUpdate() // 🔒 DATABASE ROW LOCKING
                ->exists();

            if ($exists) {
                \Illuminate\Support\Facades\DB::rollBack();
                return response()->json(['message' => 'This slot has already been taken. Please choose another time.'], 422);
            }

            // Create Locked Appointment
            $appointment = \App\Models\Appointment::create([
                'patient_id' => $request->user()->id,
                'doctor_id' => $request->doctor_id,
                'appointment_date' => $request->appointment_date,
                'start_time' => $slotStart->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'duration' => $consultationDuration,
                'status' => 'pending_payment', // 🔒 SLOT LOCKED
                'type' => $request->type,
                'reason' => $request->reason,
                'amount' => $doctorUser->doctor->consultation_fee ?? 0,
                'payment_status' => 'unpaid',
                'meeting_link' => null,
            ]);

            \Illuminate\Support\Facades\DB::commit();
            
            // Auto-create Chat Room
            \App\Models\Chat::firstOrCreate(
                ['appointment_id' => $appointment->id],
                [
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id,
                    'status' => 'pending'
                ]
            );

            return response()->json([
                'message' => 'Appointment reserved successfully',
                'appointment' => $appointment
            ]);

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Booking failed: ' . $e->getMessage()], 500);
        }
    }
    public function start(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);

        if ((int) $request->user()->id !== (int) $appointment->doctor_id) {
            return response()->json(['message' => 'Unauthorized. Only the assigned doctor can start the consultation.'], 403);
        }

        if (!in_array($appointment->status, ['confirmed', 'scheduled'])) {
             // If already ongoing, just return success
             if ($appointment->status === 'ongoing') {
                 return response()->json(['message' => 'Consultation already in progress.', 'appointment' => $appointment]);
             }
             return response()->json(['message' => 'Appointment must be confirmed to start.'], 400);
        }

        // Time Check: Exact time enforcement
        $now = now();
        $apptFullStartTime = \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->start_time);
        
        if ($now->lt($apptFullStartTime->copy()->subMinutes(5))) {
            return response()->json([
                'message' => 'Consultation can only start within 5 minutes of the scheduled time: ' . $apptFullStartTime->format('h:i A')
            ], 400);
        }

        $appointment->update([
            'status' => 'ongoing',
            'call_status' => 'ringing', // Prepare for video if it's video
            'call_started_at' => now(),
        ]);

        // If video call, notify signaling
        if ($appointment->type === 'video') {
            $this->broadcastToSignaling('call:start', [
                'appointment_id' => $appointment->id,
                'sender_id' => $appointment->doctor_id,
                'receiver_id' => $appointment->patient_id,
                'doctor_name' => $appointment->doctor->name
            ]);
        }

        return response()->json([
            'message' => 'Consultation started',
            'appointment' => $appointment
        ]);
    }


    public function startCall(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);

        if ((int) $request->user()->id !== (int) $appointment->doctor_id) {
            return response()->json(['message' => 'Unauthorized. Only the assigned doctor can start the call.'], 403);
        }

        if (!in_array($appointment->status, ['confirmed', 'scheduled', 'ongoing'])) {
             return response()->json(['message' => 'Appointment must be confirmed to start a call.'], 400);
        }

        // Time Check: Exact time enforcement
        $now = now();
        $apptFullStartTime = \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->start_time);
        
        if ($now->lt($apptFullStartTime->copy()->subMinutes(5))) {
            return response()->json([
                'message' => 'Consultation call can only start within 5 minutes of the scheduled time: ' . $apptFullStartTime->format('h:i A')
            ], 400);
        }

        $now = now();
        $startTime = \Carbon\Carbon::parse($appointment->start_time);
        if ($now->diffInMinutes($startTime, false) > 15 || $now->diffInMinutes($startTime, false) < -15) {
            // Uncomment next line to enforce strictly:
            // return response()->json(['message' => 'Consultation can only start within 15 minutes of scheduled time.'], 400);
        }

        $appointment->update([
            'call_status' => 'ringing',
            'call_started_at' => now(),
            'status' => 'ongoing'
        ]);

        $this->broadcastToSignaling('call:start', [
            'appointment_id' => $appointment->id,
            'sender_id' => $appointment->doctor_id,
            'receiver_id' => $appointment->patient_id,
            'doctor_name' => $appointment->doctor->name
        ]);

        return response()->json([
            'message' => 'Call started',
            'appointment' => $appointment
        ]);
    }

    public function endCall(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);

        if ((int) $request->user()->id !== (int) $appointment->doctor_id && (int) $request->user()->id !== (int) $appointment->patient_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $appointment->update([
            'call_status' => 'ended',
            'call_ended_at' => now()
        ]);

        $this->broadcastToSignaling('call:end', [
            'appointment_id' => $appointment->id,
            'sender_id' => $request->user()->id,
            'receiver_id' => ($request->user()->id === $appointment->doctor_id) ? $appointment->patient_id : $appointment->doctor_id
        ]);

        return response()->json(['message' => 'Call ended']);
    }

    private function broadcastToSignaling($event, $data)
    {
        // Lazy-start the signaling server if it's not running
        $this->ensureSignalingIsRunning();

        $url = config('services.signaling.url') . '/broadcast';
        try {
            Http::timeout(2)
                ->withoutVerifying()
                ->post($url, [
                'event' => $event,
                'data' => $data
            ]);
        } catch (\Exception $e) {
            \Log::error("Signaling broadcast failed: " . $e->getMessage());
        }
    }

    private function ensureSignalingIsRunning()
    {
        // Simple check: Try to connect to port from .env
        $port = env('SIGNALING_PORT', 3000);
        $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
        
        if (!is_resource($connection)) {
            // Not running! Fire the artisan command to start it in the background
            \Artisan::call('signaling:start');
        } else {
            fclose($connection);
        }
    }

    public function complete(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);

        if ((int) $request->user()->id !== (int) $appointment->doctor_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if ($appointment->status !== 'ongoing') {
            return response()->json(['message' => 'Appointment must be ongoing to complete'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $appointment->update(['status' => 'completed']);
            $this->distributeEarnings($appointment);

            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['message' => 'Consultation completed and earnings distributed', 'appointment' => $appointment]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Failed to complete appointment: ' . $e->getMessage()], 500);
        }
    }

    private function distributeEarnings($appointment)
    {
        // 1. Validation
        if (!$appointment || $appointment->earnings_distributed || $appointment->payment_status !== 'paid') {
            return;
        }

        $totalAmount = $appointment->amount;
        $doctorShare = $totalAmount * 0.60;
        $adminShare = $totalAmount * 0.40;

        // 2. Credit Doctor Wallet
        $doctorUser = $appointment->doctor;
        $doctorProfile = $doctorUser ? $doctorUser->doctor : null;
        
        if ($doctorProfile) {
            $doctorProfile->increment('wallet_balance', $doctorShare);
            
            \App\Models\Payment::create([
                'user_id' => $doctorUser->id,
                'appointment_id' => $appointment->id,
                'doctor_id' => $appointment->doctor_id,
                'reference' => 'EARN-DOC-' . $appointment->id . '-' . time(),
                'amount' => $doctorShare,
                'status' => 'success',
                'paid_at' => now(),
                'type' => 'credit',
                'method' => 'wallet',
                'description' => 'Consultation Earning (60%) - Patient: ' . ($appointment->patient->name ?? 'User')
            ]);
        }

        // 3. Credit Admin Wallet
        $admin = \App\Models\User::where('role', 'admin')->first();
        if ($admin) {
            $admin->increment('wallet_balance', $adminShare);

            \App\Models\Payment::create([
                'user_id' => $admin->id,
                'appointment_id' => $appointment->id,
                'doctor_id' => $appointment->doctor_id,
                'reference' => 'COMM-ADM-' . $appointment->id . '-' . time(),
                'amount' => $adminShare,
                'status' => 'success',
                'paid_at' => now(),
                'type' => 'credit',
                'method' => 'wallet',
                'description' => 'Platform Commission (40%) - Appt: #' . $appointment->id
            ]);
        }

        // 4. Mark Distributed
        $appointment->update(['earnings_distributed' => true]);
    }

    public function cancel(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);
        
        // Authorization check
        $userId = $request->user()->id;
        if ((int) $userId !== (int) $appointment->patient_id && (int) $userId !== (int) $appointment->doctor_id) {
            return response()->json([
                'message' => 'Unauthorized cancellation attempt',
                'debug' => [
                    'user_id' => $userId,
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id
                ]
            ], 403);
        }

        // Only allow cancellation if status is pending_payment or confirmed
        if (!in_array($appointment->status, ['pending_payment', 'confirmed'])) {
            return response()->json(['message' => 'Appointment cannot be cancelled in its current status.'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $status = 'cancelled';
            if ($request->user()->role === 'patient') {
                $status = 'cancelled_by_patient';
            } elseif ($request->user()->role === 'doctor') {
                $status = 'cancelled_by_doctor';
            }

            $appointment->update([
                'status' => $status,
                'cancellation_reason' => $request->reason
            ]);

            // Refund logic if paid
            if ($appointment->payment_status === 'paid') {
                $patientUser = $appointment->patient;
                $patientProfile = $patientUser ? $patientUser->patient : null;
                
                if ($patientProfile) {
                    $patientProfile->wallet_balance += $appointment->amount;
                    $patientProfile->save();

                    // Create Refund Payment Record
                    \App\Models\Payment::create([
                        'user_id' => $appointment->patient_id,
                        'appointment_id' => $appointment->id,
                        'reference' => 'REF-' . $appointment->id . '-' . time(),
                        'amount' => $appointment->amount,
                        'status' => 'success',
                        'paid_at' => now(),
                        'type' => 'credit', 
                        'method' => 'wallet',
                        'description' => 'Refund for cancelled appointment - Dr. ' . ($appointment->doctor->name ?? 'Specialist')
                    ]);
                }

                // REVERSE THE SPLIT if it was already distributed
                if ($appointment->earnings_distributed) {
                    $doctorShare = $appointment->amount * 0.60;
                    $adminShare = $appointment->amount * 0.40;

                    // 1. Reclaim from Doctor
                    $doctorUser = $appointment->doctor;
                    $doctorProfile = $doctorUser ? $doctorUser->doctor : null;
                    if ($doctorProfile) {
                        $doctorProfile->decrement('wallet_balance', $doctorShare);
                        
                        // Log reversal for Doctor
                        \App\Models\Payment::create([
                            'user_id' => $doctorUser->id,
                            'appointment_id' => $appointment->id,
                            'reference' => 'REV-EARN-' . $appointment->id . '-' . time(),
                            'amount' => $doctorShare,
                            'status' => 'success',
                            'paid_at' => now(),
                            'type' => 'debit',
                            'method' => 'wallet',
                            'description' => 'Reversal of Earning (Appointment Cancelled) - Patient: ' . ($patientUser->name ?? 'User')
                        ]);
                    }

                    // 2. Reclaim from Admin
                    $admin = \App\Models\User::where('role', 'admin')->first();
                    if ($admin) {
                        $admin->decrement('wallet_balance', $adminShare);

                        // Log reversal for Admin
                        \App\Models\Payment::create([
                            'user_id' => $admin->id,
                            'appointment_id' => $appointment->id,
                            'reference' => 'REV-COMM-' . $appointment->id . '-' . time(),
                            'amount' => $adminShare,
                            'status' => 'success',
                            'paid_at' => now(),
                            'type' => 'debit',
                            'method' => 'wallet',
                            'description' => 'Reversal of Commission (Appointment Cancelled) - Appt: #' . $appointment->id
                        ]);
                    }

                    $appointment->update(['earnings_distributed' => false]);
                }
            }

            \Illuminate\Support\Facades\DB::commit();
            return response()->json(['message' => 'Appointment cancelled and refunded if applicable']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            return response()->json(['message' => 'Failed to cancel appointment: ' . $e->getMessage()], 500);
        }
    }
}
