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
            $appointments = $user->appointments()->with(['doctor.doctor', 'prescription.items', 'rating'])
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
                        'prescription' => $appt->prescription,
                        'rating' => $appt->rating,
                    ];
                });
        } else {
             $appointments = \App\Models\Appointment::with(['patient', 'prescription.items', 'rating'])
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
                        'prescription' => $appt->prescription,
                        'rating' => $appt->rating,
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

        // 🔒 Auto-clean stale pending_payment locks older than 15 mins for this doctor
        \App\Models\Appointment::where('doctor_id', $id)
            ->where('status', 'pending_payment')
            ->where('created_at', '<', $serverNow->copy()->subMinutes(15))
            ->update(['status' => 'cancelled']);

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
            'type' => 'required|in:video,in-person,virtual,physical'
        ]);

        $doctorUser = \App\Models\User::with('doctor')->findOrFail($request->doctor_id);
        
        // 🔒 CRITICAL: Only allow bookings for ACTIVE doctors
        if (!$doctorUser->doctor || $doctorUser->status !== 'active' || $doctorUser->doctor->status !== 'active') {
             return response()->json(['message' => 'This doctor is currently unavailable for new bookings.'], 403);
        }

        // Normalize consultation type: 'in-person' | 'video'
        $normalizedType = in_array($request->type, ['in-person', 'physical']) ? 'in-person' : 'video';
        $docConsultationType = $doctorUser->doctor->consultation_type ?? 'both';

        if ($docConsultationType === 'virtual' && $normalizedType === 'in-person') {
            return response()->json(['message' => 'This doctor only offers virtual (video) consultations.'], 422);
        }
        if ($docConsultationType === 'physical' && $normalizedType === 'video') {
            return response()->json(['message' => 'This doctor only offers in-person (physical) consultations.'], 422);
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

        // 🔒 Auto-clean stale pending_payment locks older than 15 mins for this doctor
        \App\Models\Appointment::where('doctor_id', $request->doctor_id)
            ->where('status', 'pending_payment')
            ->where('created_at', '<', $serverNow->copy()->subMinutes(15))
            ->update(['status' => 'cancelled']);

        // 3. Double Booking / Overlap / Lock Check
        // Use Transaction & LockForUpdate for Race Condition Protection
        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            // Check for ANY overlap with active / locked appointments
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

            // Free Virtual Consultation Policy (Trial Period)
            $isVirtual = ($normalizedType === 'video');
            $amount = $isVirtual ? 0.00 : (float) ($doctorUser->doctor->consultation_fee ?? 0);
            $paymentStatus = $isVirtual ? 'free_trial' : 'unpaid';
            $appointmentStatus = $isVirtual ? 'confirmed' : 'pending_payment';

            // Create Appointment
            $appointment = \App\Models\Appointment::create([
                'patient_id' => $request->user()->id,
                'doctor_id' => $request->doctor_id,
                'appointment_date' => $request->appointment_date,
                'start_time' => $slotStart->format('H:i:s'),
                'end_time' => $slotEnd->format('H:i:s'),
                'duration' => $consultationDuration,
                'status' => $appointmentStatus,
                'type' => $normalizedType,
                'reason' => $request->reason,
                'amount' => $amount,
                'payment_status' => $paymentStatus,
                'meeting_link' => null,
            ]);

            \Illuminate\Support\Facades\DB::commit();
            
            // Auto-create Chat Room
            \App\Models\Chat::firstOrCreate(
                ['appointment_id' => $appointment->id],
                [
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id,
                    'status' => 'active'
                ]
            );

            return response()->json([
                'message' => $isVirtual ? 'Virtual consultation booked successfully (Free Trial)' : 'Appointment reserved successfully',
                'appointment' => $appointment->load('doctor.doctor')
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
        try {
            // Simple check: Try to connect to port from .env
            $port = env('SIGNALING_PORT', 3000);
            $connection = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            
            if (!is_resource($connection)) {
                // Not running! Fire the artisan command to start it in the background if in console or supported
                if (function_exists('exec') || function_exists('popen')) {
                    \Artisan::call('signaling:start');
                }
            } else {
                fclose($connection);
            }
        } catch (\Throwable $e) {
            \Log::warning("Could not auto-start signaling server: " . $e->getMessage());
        }
    }

    public function complete(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::with(['doctor.doctor', 'patient'])->findOrFail($id);
        $doctorUser = $request->user();

        if ((int) $doctorUser->id !== (int) $appointment->doctor_id) {
            return response()->json(['message' => 'Unauthorized. Only the assigned doctor can document and complete this consultation.'], 403);
        }

        if (!in_array($appointment->status, ['ongoing', 'confirmed', 'scheduled'])) {
            return response()->json(['message' => 'Appointment must be active or ongoing to complete'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $appointment->update([
                'status' => 'completed',
                'call_status' => 'ended',
                'call_ended_at' => $appointment->call_ended_at ?? now(),
            ]);

            $diagnosis = $request->input('diagnosis') ?: ($appointment->reason ?: 'General Clinical Consultation');
            $clinicalNotes = $request->input('clinical_notes') ?: $request->input('soap_notes');
            $chiefComplaint = $request->input('chief_complaint') ?: $appointment->reason;
            $treatmentPlan = $request->input('treatment_plan');
            $followUp = $request->input('follow_up') ?: $request->input('follow_up_advice');
            $vitals = $request->input('vitals', []);

            // 1. Standardized Clinical Encounter Summary
            $formattedDescription = [];
            if ($chiefComplaint) {
                $formattedDescription[] = "• Chief Complaint / Symptoms: " . $chiefComplaint;
            }
            if ($diagnosis) {
                $formattedDescription[] = "• Clinical Diagnosis / Assessment: " . $diagnosis;
            }
            if (!empty($vitals) && is_array($vitals)) {
                $vitalSummary = [];
                if (!empty($vitals['bp'])) $vitalSummary[] = "BP: " . $vitals['bp'];
                if (!empty($vitals['pulse'])) $vitalSummary[] = "Pulse: " . $vitals['pulse'] . " bpm";
                if (!empty($vitals['temp'])) $vitalSummary[] = "Temp: " . $vitals['temp'] . " °C";
                if (!empty($vitals['spo2'])) $vitalSummary[] = "SpO2: " . $vitals['spo2'] . "%";
                if (!empty($vitals['weight'])) $vitalSummary[] = "Weight: " . $vitals['weight'] . " kg";
                if (!empty($vitalSummary)) {
                    $formattedDescription[] = "• Recorded Vitals: " . implode(', ', $vitalSummary);
                }
            }
            if ($clinicalNotes) {
                $formattedDescription[] = "• Clinical Notes & Observations:\n" . $clinicalNotes;
            }
            if ($treatmentPlan) {
                $formattedDescription[] = "• Treatment Plan & Patient Advice:\n" . $treatmentPlan;
            }
            if ($followUp) {
                $formattedDescription[] = "• Follow-up Recommendation: " . $followUp;
            }

            $finalSummaryText = implode("\n\n", $formattedDescription);
            $facilityName = ($doctorUser->doctor && $doctorUser->doctor->workplace_name) ? $doctorUser->doctor->workplace_name : 'Idibia Health Network';

            // 2. Automatically Create Consultation Note in Medical Records
            \App\Models\MedicalRecord::create([
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $doctorUser->id,
                'type' => 'Consultation Note',
                'title' => 'Clinical Encounter: ' . $diagnosis,
                'doctor_name' => 'Dr. ' . $doctorUser->name,
                'record_date' => now()->toDateString(),
                'status' => 'Completed',
                'facility' => $facilityName,
                'description' => $finalSummaryText ?: ('Consultation encounter completed with Dr. ' . $doctorUser->name . '. Diagnosis: ' . $diagnosis),
            ]);

            // 3. Process E-Prescription Items if provided
            $prescriptionItems = $request->input('prescription_items', []);
            if (!empty($prescriptionItems) && is_array($prescriptionItems)) {
                $validItems = array_filter($prescriptionItems, function($i) {
                    return !empty($i['medication_name']);
                });

                if (!empty($validItems)) {
                    $prescriptionNumber = 'RX-' . strtoupper(date('ymd')) . '-' . strtoupper(\Illuminate\Support\Str::random(5));
                    $totalAmount = 0.00;
                    foreach ($validItems as $v) {
                        $qty = (int) ($v['quantity'] ?? 1);
                        $unitPrice = (float) ($v['unit_price'] ?? 0.00);
                        $totalAmount += ($qty * $unitPrice);
                    }

                    $prescription = \App\Models\Prescription::create([
                        'prescription_number' => $prescriptionNumber,
                        'patient_id' => $appointment->patient_id,
                        'doctor_id' => $doctorUser->id,
                        'appointment_id' => $appointment->id,
                        'diagnosis' => $diagnosis,
                        'clinical_notes' => $clinicalNotes ?? $treatmentPlan,
                        'total_amount' => $totalAmount,
                        'payment_status' => $totalAmount > 0 ? 'unpaid' : 'paid',
                        'status' => 'issued',
                        'paid_at' => $totalAmount > 0 ? null : now(),
                    ]);

                    foreach ($validItems as $item) {
                        $qty = (int) ($item['quantity'] ?? 1);
                        $unitPrice = (float) ($item['unit_price'] ?? 0.00);
                        $prescription->items()->create([
                            'medication_name' => $item['medication_name'],
                            'dosage_form' => $item['dosage_form'] ?? 'Tablet',
                            'strength' => $item['strength'] ?? null,
                            'frequency' => $item['frequency'] ?? 'As directed',
                            'duration' => $item['duration'] ?? '5 days',
                            'instructions' => $item['instructions'] ?? null,
                            'quantity' => $qty,
                            'unit_price' => $unitPrice,
                            'total_price' => $qty * $unitPrice,
                        ]);
                    }

                    \App\Models\MedicalRecord::create([
                        'patient_id' => $appointment->patient_id,
                        'doctor_id' => $doctorUser->id,
                        'type' => 'Prescription',
                        'title' => 'E-Prescription (℞): ' . $diagnosis,
                        'doctor_name' => 'Dr. ' . $doctorUser->name,
                        'record_date' => now()->toDateString(),
                        'status' => 'Active',
                        'facility' => $facilityName,
                        'description' => 'Prescription #' . $prescriptionNumber . ' issued with ' . count($validItems) . ' medication(s).',
                    ]);
                }
            }

            // 4. Process Diagnostic / Lab Orders if provided
            $labOrders = $request->input('lab_orders', []);
            if (!empty($labOrders) && is_array($labOrders)) {
                foreach ($labOrders as $test) {
                    $testName = is_array($test) ? ($test['test_name'] ?? $test['name'] ?? 'Diagnostic Test') : (string) $test;
                    if (!empty(trim($testName))) {
                        \App\Models\MedicalRecord::create([
                            'patient_id' => $appointment->patient_id,
                            'doctor_id' => $doctorUser->id,
                            'type' => 'Lab Result',
                            'title' => 'Diagnostic Order: ' . trim($testName),
                            'doctor_name' => 'Dr. ' . $doctorUser->name,
                            'record_date' => now()->toDateString(),
                            'status' => 'Ordered',
                            'facility' => $facilityName,
                            'description' => 'Clinical laboratory investigation ordered by Dr. ' . $doctorUser->name . ' for diagnosis: ' . $diagnosis,
                        ]);
                    }
                }
            }

            // 5. Send Database Notification to Patient
            try {
                \Illuminate\Support\Facades\DB::table('notifications')->insert([
                    'id' => (string) \Illuminate\Support\Str::uuid(),
                    'type' => 'App\Notifications\ConsultationCompleted',
                    'notifiable_type' => 'App\Models\User',
                    'notifiable_id' => $appointment->patient_id,
                    'data' => json_encode([
                        'title' => 'Consultation Summary & Prescription Ready',
                        'message' => 'Dr. ' . $doctorUser->name . ' has documented your consultation summary and issued your clinical notes/e-prescriptions in your Medical Records.',
                        'appointment_id' => $appointment->id,
                        'type' => 'medical_record',
                    ]),
                    'read_at' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (\Throwable $notifEx) {
                \Illuminate\Support\Facades\Log::warning('Failed to insert consultation notification: ' . $notifEx->getMessage());
            }

            // 6. Distribute earnings
            $this->distributeEarnings($appointment);

            \Illuminate\Support\Facades\DB::commit();

            return response()->json([
                'message' => 'Consultation encounter completed, clinical summary saved, and patient chart updated successfully.',
                'appointment' => $appointment->fresh(['doctor.doctor', 'prescription.items', 'patient'])
            ]);
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
        $user = $request->user();
        $userId = $user->id;
        $userRole = $user->role;
        $isAdmin = in_array($userRole, ['admin', 'super-admin']);

        if ((int) $userId !== (int) $appointment->patient_id && (int) $userId !== (int) $appointment->doctor_id && !$isAdmin) {
            return response()->json([
                'message' => 'Unauthorized cancellation attempt',
                'debug' => [
                    'user_id' => $userId,
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id
                ]
            ], 403);
        }

        // Only allow cancellation if status is pending, pending_payment, confirmed, or scheduled
        if (!in_array($appointment->status, ['pending', 'pending_payment', 'confirmed', 'scheduled'])) {
            return response()->json(['message' => 'Appointment cannot be cancelled in its current status.'], 400);
        }

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            $status = 'cancelled';
            if ($userRole === 'patient') {
                $status = 'cancelled_by_patient';
            } elseif ($userRole === 'doctor') {
                $status = 'cancelled_by_doctor';
            } elseif ($isAdmin) {
                $status = 'cancelled_by_admin';
            }

            $appointment->update([
                'status' => $status,
                'cancellation_reason' => $request->reason ?? ($isAdmin ? 'Cancelled by Administrator' : null)
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

    /**
     * Rate and review doctor after consultation.
     */
    public function rateDoctor(Request $request, $id)
    {
        $user = $request->user();
        $appointment = \App\Models\Appointment::with('doctor.doctor')->findOrFail($id);

        if ((int) $appointment->patient_id !== (int) $user->id) {
            return response()->json(['message' => 'Unauthorized to rate this consultation.'], 403);
        }

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
            'tags' => 'nullable|array',
        ]);

        $rating = \App\Models\DoctorRating::updateOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'patient_id' => $user->id,
                'doctor_id' => $appointment->doctor_id,
                'rating' => $validated['rating'],
                'comment' => $validated['comment'] ?? null,
                'tags' => $validated['tags'] ?? [],
            ]
        );

        // Recalculate Doctor's aggregate rating
        $doctorUser = $appointment->doctor;
        if ($doctorUser && $doctorUser->doctor) {
            $avgRating = \App\Models\DoctorRating::where('doctor_id', $doctorUser->id)->avg('rating');
            $reviewsCount = \App\Models\DoctorRating::where('doctor_id', $doctorUser->id)->count();

            $doctorUser->doctor->update([
                'rating' => round($avgRating ?? 5.0, 2),
                'reviews_count' => $reviewsCount,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Thank you for your rating and feedback!',
            'rating' => $rating,
        ]);
    }
}
