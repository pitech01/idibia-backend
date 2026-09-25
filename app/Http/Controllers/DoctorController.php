<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Appointment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DoctorController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'specialty' => 'required|string',
            'experience_years' => 'required|integer',
            'license_number' => 'required|string',
            'issuing_authority' => 'required|string',
            'practice_type' => 'nullable|string',
            'workplace_name' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'consultation_type' => 'nullable|string',
            'bio' => 'nullable|string',
            'license_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
            'id_document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', // 5MB
        ]);

        $user = $request->user();

        // Handle File Uploads
        $licensePath = null;
        if ($request->hasFile('license_document')) {
            $licensePath = $request->file('license_document')->store('doctors/licenses', 'public');
        }

        $idPath = null;
        if ($request->hasFile('id_document')) {
            $idPath = $request->file('id_document')->store('doctors/ids', 'public');
        }

        $doctor = \App\Models\Doctor::updateOrCreate(
            ['user_id' => $user->id],
            [
                'specialty' => $validated['specialty'],
                'experience_years' => $validated['experience_years'],
                'license_number' => $validated['license_number'],
                'issuing_authority' => $validated['issuing_authority'],
                'practice_type' => $validated['practice_type'] ?? null,
                'workplace_name' => $validated['workplace_name'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'consultation_type' => $validated['consultation_type'] ?? 'both',
                'bio' => $validated['bio'] ?? null,
                'license_document_path' => $licensePath,
                'id_document_path' => $idPath,
                'status' => 'pending_approval'
            ]
        );

        // Send Notification Email
        try {
            \Illuminate\Support\Facades\Mail::to($user->email)->send(new \App\Mail\DoctorApplicationReceived($user));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send doctor application email: ' . $e->getMessage());
        }

        return response()->json(['message' => 'Doctor profile submitted for review.', 'doctor' => $doctor]);
    }
    public function dashboard(Request $request)
    {
        $user = $request->user();
        
        // Clean up stale pending_payment older than 15 mins
        Appointment::where('doctor_id', $user->id)
            ->where('status', 'pending_payment')
            ->where('created_at', '<', Carbon::now()->subMinutes(15))
            ->update(['status' => 'cancelled']);

        // 1. Pending Requests (Count)
        $pendingRequests = Appointment::where('doctor_id', $user->id)
            ->whereIn('status', ['pending', 'pending_payment'])
            ->where('created_at', '>=', Carbon::now()->subMinutes(15))
            ->count();

        // 2. Today's Appointments (Count)
        $today = Carbon::today()->toDateString();
        $todayAppointmentsCount = Appointment::where('doctor_id', $user->id)
            ->where('appointment_date', $today)
            ->whereIn('status', ['confirmed', 'ongoing', 'completed'])
            ->count();

        // 3. Earnings (Sum 60% share from payments)
        $earnings = \App\Models\Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('reference', 'like', 'EARN-DOC-%')
            ->sum('amount');

        // 4. Rating (Mock)
        $rating = 4.9;

        // 5. Today's Schedule
        $todaySchedule = Appointment::with('patient')
            ->where('doctor_id', $user->id)
            ->where('appointment_date', $today)
            ->whereIn('status', ['confirmed', 'ongoing', 'completed', 'pending_payment'])
            ->orderBy('start_time', 'asc')
            ->get();

        // 6. Active or Next Appointment
        $recentPatientAppointment = Appointment::with('patient')
            ->where('doctor_id', $user->id)
            ->where('status', 'ongoing')
            ->first();

        if (!$recentPatientAppointment) {
            $recentPatientAppointment = Appointment::with('patient')
                ->where('doctor_id', $user->id)
                ->whereIn('status', ['confirmed', 'pending_payment'])
                ->where('appointment_date', '>=', Carbon::today()->toDateString())
                ->orderBy('appointment_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->first();
        }

        if (!$recentPatientAppointment) {
             $recentPatientAppointment = Appointment::with('patient')
                ->where('doctor_id', $user->id)
                ->where('status', 'completed')
                ->orderBy('appointment_date', 'desc')
                ->orderBy('start_time', 'desc')
                ->first();
        }

        // 7. Upcoming Appointments (List of next 5)
        $now = Carbon::now();
        $upcoming = Appointment::with('patient')
            ->where('doctor_id', $user->id)
            ->whereIn('status', ['confirmed', 'pending_payment'])
            ->where(function($q) use ($now) {
                $q->where('appointment_date', '>', $now->toDateString())
                  ->orWhere('appointment_date', $now->toDateString());
            })
            ->orderBy('appointment_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->limit(5)
            ->get();

        // 8. Unread Messages for Dashboard
        $unreadMessagesCount = \App\Models\Message::whereHas('chat', function($q) use ($user) {
                $q->where('doctor_id', $user->id);
            })
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->count();

        $latestUnreadMessage = \App\Models\Message::whereHas('chat', function($q) use ($user) {
                $q->where('doctor_id', $user->id);
            })
            ->where('sender_id', '!=', $user->id)
            ->whereNull('read_at')
            ->with(['sender.patient', 'chat'])
            ->latest()
            ->first();

        
        // Return appointment itself so we can act on it
        // $recentPatient = $recentPatientAppointment ? $recentPatientAppointment->patient : null;


        return response()->json([
            'stats' => [
                'pending_requests' => $pendingRequests,
                'today_appointments' => $todayAppointmentsCount,
                'earnings' => $earnings,
                'rating' => $rating,
                'unread_messages_count' => $unreadMessagesCount
            ],
            'today_schedule' => $todaySchedule,
            'recent_appointment' => $recentPatientAppointment,
            'upcoming' => $upcoming,
            'latest_unread_message' => $latestUnreadMessage,
            'user' => $user
        ]);
    }

    public function patients(Request $request)
    {
        $user = $request->user();
        
        // Get unique patients who have had appointments with this doctor
        $patientIds = Appointment::where('doctor_id', $user->id)
            ->pluck('patient_id')
            ->unique();
            
        $patients = User::whereIn('id', $patientIds)
            ->with(['patient'])
            ->get()
            ->map(function ($patientUser) use ($user) {
                $appointments = Appointment::where('doctor_id', $user->id)
                    ->where('patient_id', $patientUser->id)
                    ->orderBy('appointment_date', 'desc')
                    ->orderBy('start_time', 'desc')
                    ->get();

                $latestAppt = $appointments->first();
                $prescriptions = \App\Models\Prescription::where('doctor_id', $user->id)
                    ->where('patient_id', $patientUser->id)
                    ->with('items')
                    ->latest()
                    ->get();

                $records = \App\Models\MedicalRecord::where('patient_id', $patientUser->id)
                    ->where(function($q) use ($user) {
                        $q->where('doctor_id', $user->id)->orWhereNull('doctor_id');
                    })
                    ->latest()
                    ->get();

                $hasUpcoming = $appointments->whereIn('status', ['confirmed', 'ongoing'])->count() > 0;
                $status = $hasUpcoming ? 'Active' : ($appointments->where('status', 'completed')->count() > 0 ? 'Completed' : 'Active');

                return [
                    'id' => $patientUser->id,
                    'name' => $patientUser->name,
                    'email' => $patientUser->email,
                    'phone' => $patientUser->phone,
                    'avatar' => $patientUser->avatar,
                    'patient' => $patientUser->patient,
                    'appointments' => $appointments,
                    'prescriptions' => $prescriptions,
                    'records' => $records,
                    'total_visits' => $appointments->count(),
                    'last_visit' => $latestAppt ? $latestAppt->appointment_date->format('Y-m-d') : 'N/A',
                    'latest_reason' => $latestAppt ? $latestAppt->reason : 'General Consultation',
                    'status' => $status
                ];
            });

        return response()->json($patients);
    }

    public function schedule(Request $request)
    {
        $user = $request->user();
        $date = $request->query('date', Carbon::today()->toDateString());

        // Clean up stale pending_payment older than 15 mins
        Appointment::where('doctor_id', $user->id)
            ->where('status', 'pending_payment')
            ->where('created_at', '<', Carbon::now()->subMinutes(15))
            ->update(['status' => 'cancelled']);

        // Daily Appointments (for Timeline)
        $appointments = Appointment::with('patient')
            ->where('doctor_id', $user->id)
            ->whereDate('appointment_date', $date)
            ->whereIn('status', ['confirmed', 'ongoing', 'completed', 'pending_payment'])
            ->orderBy('start_time', 'asc')
            ->get();

        // Upcoming Appointments (for List - Future only)
        $now = Carbon::now();
        $upcoming = Appointment::with('patient')
            ->where('doctor_id', $user->id)
            ->whereIn('status', ['confirmed', 'ongoing']) // Confirmed or ongoing for future list
            ->where(function($q) use ($now) {
                $q->where('appointment_date', '>', $now->toDateString())
                  ->orWhere('appointment_date', $now->toDateString());
            })
            ->orderBy('appointment_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->limit(10)
            ->get();

        $pendingCount = Appointment::where('doctor_id', $user->id)
            ->whereIn('status', ['pending', 'pending_payment'])
            ->where('created_at', '>=', Carbon::now()->subMinutes(15))
            ->count();

        return response()->json([
            'appointments' => $appointments,
            'upcoming' => $upcoming,
            'pending_count' => $pendingCount
        ]);
    }

    public function getAvailability(Request $request)
    {
        $user = $request->user();
        
        // Ensure relationships are loaded
        $user->load('doctor');

        if (!$user->doctor) {
            // Check if user IS the doctor (if fetching as patient view? No, this is doctor dashboard)
            // Or if doctor record exists
             return response()->json(['message' => 'Doctor profile not found'], 404);
        }

        $availabilities = \App\Models\DoctorAvailability::where('doctor_id', $user->doctor->id)->get();
        return response()->json([
            'availabilities' => $availabilities,
            'doctor' => $user->doctor
        ]);
    }

    public function updateAvailability(Request $request)
    {
        $user = $request->user();
        if (!$user->doctor) {
            return response()->json(['message' => 'Doctor profile not found'], 404);
        }

        $validated = $request->validate([
            'availabilities' => 'required|array',
            'availabilities.*.day' => 'required|string',
            'availabilities.*.start_time' => 'required',
            'availabilities.*.end_time' => 'required',
            'availabilities.*.is_available' => 'required|boolean',
            'consultation_duration' => 'nullable|integer|min:5'
        ]);

        if (isset($validated['consultation_duration'])) {
            $user->doctor->update(['consultation_duration' => $validated['consultation_duration']]);
        }

        // Delete existing and replace (Simpler for now)
        \App\Models\DoctorAvailability::where('doctor_id', $user->doctor->id)->delete();

        foreach ($validated['availabilities'] as $slot) {
            \App\Models\DoctorAvailability::create([
                'doctor_id' => $user->doctor->id,
                'day' => $slot['day'],
                'start_time' => $slot['start_time'],
                'end_time' => $slot['end_time'],
                'is_available' => $slot['is_available'],
            ]);
        }

        return response()->json(['message' => 'Availability updated']);
    }

    public function getProfile(Request $request)
    {
        $user = $request->user();
        $user->load('doctor');
        return response()->json([
            'user' => $user,
            'doctor' => $user->doctor
        ]);
    }

    public function updateProfile(Request $request)
    {
        $user = $request->user();
        $doctor = $user->doctor;

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'specialty' => 'nullable|string|max:255',
            'experience_years' => 'nullable|integer',
            'bio' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'consultation_type' => 'nullable|string|in:virtual,physical,both',
            'consultation_duration' => 'nullable|integer|min:5',
            'practice_name' => 'nullable|string',
            'practice_address' => 'nullable|string',
            'settings' => 'nullable|array',
        ]);

        $user->update([
            'name' => $validated['name'],
            'settings' => $validated['settings'] ?? $user->settings
        ]);

        if ($doctor) {
            $doctor->update([
                'specialty' => $validated['specialty'],
                'experience_years' => $validated['experience_years'],
                'bio' => $validated['bio'],
                'city' => $validated['city'],
                'state' => $validated['state'],
                'consultation_type' => $validated['consultation_type'],
                'consultation_duration' => $validated['consultation_duration'] ?? $doctor->consultation_duration,
                'workplace_name' => $validated['practice_name'] ?? $doctor->workplace_name,
                'practice_address' => $validated['practice_address'] ?? $doctor->practice_address,
            ]);
        }

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user->fresh('doctor')
        ]);
    }

    public function getEarnings(Request $request)
    {
        $user = $request->user();
        
        $allDoctorPayments = \App\Models\Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('reference', 'like', 'EARN-DOC-%');

        $totalEarnings = (clone $allDoctorPayments)->sum('amount');
        
        $monthlyEarnings = (clone $allDoctorPayments)
            ->whereMonth('paid_at', date('m'))
            ->whereYear('paid_at', date('Y'))
            ->sum('amount');
            
        $todayEarnings = (clone $allDoctorPayments)
            ->whereDate('paid_at', date('Y-m-d'))
            ->sum('amount');

        $recentTransactions = (clone $allDoctorPayments)
            ->with(['user']) // We might want to link back to patient via appointment_id
            ->orderBy('paid_at', 'desc')
            ->limit(10)
            ->get()
            ->map(function($payment) {
                // Get appointment to find patient name
                $appt = \App\Models\Appointment::with('patient')->find($payment->appointment_id);
                return [
                    'id' => $payment->id,
                    'patient_name' => $appt ? $appt->patient->name : 'N/A',
                    'date' => $payment->paid_at->toDateString(),
                    'time' => $payment->paid_at->toTimeString(),
                    'amount' => $payment->amount,
                    'status' => 'paid',
                    'description' => $payment->description
                ];
            });

        return response()->json([
            'summary' => [
                'total' => $totalEarnings,
                'monthly' => $monthlyEarnings,
                'today' => $todayEarnings,
            ],
            'transactions' => $recentTransactions
        ]);
    }
    



    public function exportEarnings(Request $request)
    {
        $user = $request->user();
        $fileName = 'earnings_export_' . date('Y-m-d') . '.csv';

        $payments = \App\Models\Payment::where('user_id', $user->id)
            ->where('status', 'success')
            ->where('reference', 'like', 'EARN-DOC-%')
            ->orderBy('paid_at', 'desc')
            ->get();

        $headers = [
            "Content-type"        => "text/csv",
            "Content-Disposition" => "attachment; filename=$fileName",
            "Pragma"              => "no-cache",
            "Cache-Control"       => "must-revalidate, post-check=0, pre-check=0",
            "Expires"             => "0"
        ];

        $columns = ['Transaction ID', 'Patient Name', 'Date', 'Amount', 'Status', 'Description'];

        $callback = function() use($payments, $columns) {
            $file = fopen('php://output', 'w');
            fputcsv($file, $columns);

            foreach ($payments as $payment) {
                $appt = \App\Models\Appointment::with('patient')->find($payment->appointment_id);
                $row['Transaction ID'] = $payment->reference;
                $row['Patient Name']  = $appt ? $appt->patient->name : 'N/A';
                $row['Date']          = $payment->paid_at->toDateTimeString();
                $row['Amount']        = $payment->amount;
                $row['Status']        = $payment->status;
                $row['Description']   = $payment->description;

                fputcsv($file, array($row['Transaction ID'], $row['Patient Name'], $row['Date'], $row['Amount'], $row['Status'], $row['Description']));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
