<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class AppointmentController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        if ($user->role === 'patient') {
            $appointments = \App\Models\Appointment::with(['doctor'])
                ->where('patient_id', $user->id)
                ->orderBy('appointment_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        } else {
             $appointments = \App\Models\Appointment::with(['patient'])
                ->where('doctor_id', $user->id)
                ->orderBy('appointment_date', 'asc')
                ->orderBy('start_time', 'asc')
                ->get();
        }

        return response()->json($appointments);
    }

    public function store(Request $request)
    {
        $request->validate([
            'doctor_id' => 'required|exists:users,id',
            'appointment_date' => 'required|date|after_or_equal:today',
            'start_time' => 'required',
            'reason' => 'nullable|string',
            'type' => 'required|in:video,in-person'
        ]);

        // Basic slot availability check (very simple)
        $exists = \App\Models\Appointment::where('doctor_id', $request->doctor_id)
            ->where('appointment_date', $request->appointment_date)
            ->where('start_time', $request->start_time)
            ->where('status', '!=', 'cancelled')
            ->exists();

        if ($exists) {
            return response()->json(['message' => 'Slot already taken'], 422);
        }

        // Calculate end_time (default 30 mins)
        $endTime = date('H:i:s', strtotime($request->start_time) + 1800);

        $appointment = \App\Models\Appointment::create([
            'patient_id' => $request->user()->id,
            'doctor_id' => $request->doctor_id,
            'appointment_date' => $request->appointment_date,
            'start_time' => $request->start_time,
            'end_time' => $endTime,
            'status' => 'pending',
            'type' => $request->type,
            'reason' => $request->reason,
            'meeting_link' => $request->type === 'video' ? 'https://meet.jit.si/' . uniqid() : null, // Mock link
        ]);

        return response()->json(['message' => 'Appointment booked successfully', 'appointment' => $appointment]);
    }

    public function cancel(Request $request, $id)
    {
        $appointment = \App\Models\Appointment::findOrFail($id);
        
        // Authorization check
        if ($request->user()->id !== $appointment->patient_id && $request->user()->id !== $appointment->doctor_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $appointment->update(['status' => 'cancelled']);

        return response()->json(['message' => 'Appointment cancelled']);
    }
}
