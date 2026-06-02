<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    // Middleware to ensure only admins can access these methods
    public function __construct()
    {
        // simplistic check, ideally use a middleware class
        // $this->middleware('can:admin'); 
    }

    private function ensureAdmin(Request $request)
    {
        if ($request->user()->role !== 'admin') {
            abort(403, 'Unauthorized action.');
        }
    }

    public function getPendingDoctors(Request $request)
    {
        $this->ensureAdmin($request);

        $doctors = Doctor::with('user')
            ->where('status', 'pending_approval')
            ->get();

        return response()->json($doctors);
    }

    public function getAllDoctors(Request $request)
    {
        $this->ensureAdmin($request);

        $doctors = User::where('role', 'doctor')
            ->with('doctor')
            ->get();

        return response()->json($doctors);
    }

    public function approveDoctor(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $doctor = Doctor::findOrFail($id);
        $doctor->update([
            'status' => 'active',
            'is_verified' => true
        ]);

        // Send Approval Email
        try {
            // Need to load user if not loaded
            if (!$doctor->relationLoaded('user')) {
                $doctor->load('user');
            }
            \Illuminate\Support\Facades\Mail::to($doctor->user->email)->send(new \App\Mail\DoctorApplicationApproved($doctor->user));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Failed to send approval email: ' . $e->getMessage());
        }
        
        return response()->json(['message' => 'Doctor approved successfully', 'doctor' => $doctor]);
    }

    public function rejectDoctor(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $doctor = Doctor::findOrFail($id);
        $doctor->update([
            'status' => 'rejected',
            'is_verified' => false
        ]);

        return response()->json(['message' => 'Doctor application rejected', 'doctor' => $doctor]);
    }

    public function suspendDoctor(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $doctor = Doctor::findOrFail($id);
        $doctor->update(['status' => 'suspended']);
        
        // Also suspend the user account for full effect
        $doctor->user->update(['status' => 'suspended']);

        return response()->json(['message' => 'Doctor account suspended successfully', 'doctor' => $doctor]);
    }

    public function activateDoctor(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $doctor = Doctor::findOrFail($id);
        $doctor->update(['status' => 'active']);
        $doctor->user->update(['status' => 'active']);

        return response()->json(['message' => 'Doctor account activated successfully', 'doctor' => $doctor]);
    }

    public function getPatients(Request $request)
    {
        $this->ensureAdmin($request);

        $patients = User::where('role', 'patient')
            ->with('patient')
            ->get();

        return response()->json($patients);
    }

    public function getMedicalRecords(Request $request)
    {
        $this->ensureAdmin($request);

        $records = \App\Models\MedicalRecord::with('patient', 'doctor')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($records);
    }

    public function updateDoctorPricing(Request $request, $id)
    {
        $this->ensureAdmin($request);

        $request->validate([
            'consultation_fee' => 'required|numeric|min:0'
        ]);

        $doctor = Doctor::findOrFail($id);
        $doctor->update([
            'consultation_fee' => $request->consultation_fee
        ]);

        return response()->json([
            'message' => 'Consultation fee updated successfully',
            'doctor' => $doctor
        ]);
    }

    public function getDashboardStats(Request $request)
    {
        $this->ensureAdmin($request);

        $doctorCount = Doctor::count();
        $patientCount = User::where('role', 'patient')->count();
        $ticketCount = \App\Models\SupportTicket::where('status', 'open')->count();
        
        // Financial Stats
        $admin = $request->user();
        $totalCommissions = \App\Models\Payment::where('user_id', $admin->id)
            ->where('status', 'success')
            ->where('reference', 'like', 'COMM-ADM-%')
            ->sum('amount');

        $recentEarnings = \App\Models\Payment::where('user_id', $admin->id)
            ->where('status', 'success')
            ->where('reference', 'like', 'COMM-ADM-%')
            ->latest('paid_at')
            ->limit(10)
            ->get()
            ->map(function($payment) {
                return [
                    'id' => $payment->id,
                    'amount' => $payment->amount,
                    'date' => $payment->paid_at->toDateString(),
                    'description' => $payment->description,
                ];
            });

        return response()->json([
            'doctors' => $doctorCount,
            'patients' => $patientCount,
            'tickets' => $ticketCount,
            'financials' => [
                'total_commissions' => $totalCommissions,
                'wallet_balance' => $admin->wallet_balance,
                'recent_earnings' => $recentEarnings
            ]
        ]);
    }


    public function getAppointments(Request $request)
    {
        $this->ensureAdmin($request);

        $appointments = \App\Models\Appointment::with(['doctor.doctor', 'patient'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($appt) {
                return [
                    'id' => $appt->id,
                    'patient_name' => $appt->patient->name ?? 'Unknown',
                    'doctor_name' => $appt->doctor->name ?? 'Unknown',
                    'date' => $appt->appointment_date,
                    'time' => $appt->start_time,
                    'status' => $appt->status,
                    'duration' => $appt->duration ?? 30,
                    'payment_status' => $appt->payment_status,
                    'amount' => $appt->amount,
                    'earnings_distributed' => $appt->earnings_distributed
                ];
            });

        return response()->json($appointments);
    }
    public function deleteDoctor(Request $request, $id)
    {
        $this->ensureAdmin($request);
        $doctor = Doctor::findOrFail($id);
        $user = $doctor->user;
        
        // Deleting the user will cascade delete the doctor record
        if ($user) {
            $user->delete();
        } else {
            $doctor->delete();
        }
        
        return response()->json(['message' => 'Doctor and associated user account deleted successfully']);
    }

    public function deletePatient(Request $request, $id)
    {
        $this->ensureAdmin($request);
        $user = User::where('role', 'patient')->findOrFail($id);
        
        // Deleting the user will cascade delete the patient record
        $user->delete();
        
        return response()->json(['message' => 'Patient and associated user account deleted successfully']);
    }

    public function suspendPatient(Request $request, $id)
    {
        $this->ensureAdmin($request);
        $user = User::where('role', 'patient')->findOrFail($id);
        $user->update(['status' => 'suspended']);
        
        return response()->json(['message' => 'Patient account suspended successfully']);
    }

    public function activatePatient(Request $request, $id)
    {
        $this->ensureAdmin($request);
        $user = User::where('role', 'patient')->findOrFail($id);
        $user->update(['status' => 'active']);
        
        return response()->json(['message' => 'Patient account activated successfully']);
    }
}

