<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PatientDashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. Upcoming Appointment
        $upcomingAppointment = \App\Models\Appointment::with('doctor')
            ->where('patient_id', $user->id)
            ->where('status', 'not like', 'cancelled%')
            ->where('status', '!=', 'completed')
            ->where('appointment_date', '>=', now()->toDateString())
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->first();

        if ($upcomingAppointment) {
            $upcomingAppointment->iso_start_time = \Carbon\Carbon::parse($upcomingAppointment->appointment_date->format('Y-m-d') . ' ' . $upcomingAppointment->start_time)->toIso8601String();
        }

        // 2. Wallet Balance
        // Ensure patient relation is loaded or accessed
        $walletBalance = $user->patient ? $user->patient->wallet_balance : 0.00;

        // 3. Vitals
        $vitals = \App\Models\Vital::where('patient_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->unique('type')
            ->values();

        // 4. Recent Activity (Appointments & Payments)
        $activities = collect();

        // Appointments
        $recentAppointments = \App\Models\Appointment::with('doctor')
            ->where('patient_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get()
            ->map(function ($apt) {
                return [
                    'id' => 'apt-' . $apt->id,
                    'type' => 'appointment',
                    'title' => 'Appointment: ' . ($apt->doctor ? $apt->doctor->name : 'Doctor'),
                    'subtitle' => $apt->reason ?? 'General Consultation',
                    'date' => $apt->created_at,
                    'status' => $apt->status,
                    'amount' => null
                ];
            });
        
        // Payments
        $recentPayments = \App\Models\Payment::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->take(3)
            ->get()
            ->map(function ($pay) {
                return [
                    'id' => 'pay-' . $pay->id,
                    'type' => 'payment',
                    'title' => 'Payment: ' . $pay->description,
                    'subtitle' => $pay->method,
                    'date' => $pay->created_at,
                    'status' => $pay->status,
                    'amount' => $pay->amount
                ];
            });

        // Merge and sort
        $activities = $activities->concat($recentAppointments)->concat($recentPayments)
            ->sortByDesc('date')
            ->take(5)
            ->values();

        return response()->json([
            'upcoming_appointment' => $upcomingAppointment,
            'wallet_balance' => $walletBalance,
            'vitals' => $vitals,
            'recent_activity' => $activities
        ]);
    }
}
