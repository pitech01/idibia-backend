<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        
        return \App\Models\Chat::with(['latestMessage', 'patient', 'doctor'])
            ->where('patient_id', $userId)
            ->orWhere('doctor_id', $userId)
            ->latest('updated_at')
            ->get();
    }

    public function show(Request $request, $id)
    {
        $userId = $request->user()->id;
        $chat = \App\Models\Chat::with(['patient', 'doctor'])
            ->where('id', $id)
            ->where(function ($query) use ($userId) {
                $query->where('patient_id', $userId)
                      ->orWhere('doctor_id', $userId);
            })
            ->firstOrFail();

        // Check expiry and update status if needed
        if ($chat->status === 'active' && $chat->is_expired) {
            $chat->update(['status' => 'expired']);
        }

        return response()->json([
            'chat' => $chat,
            'messages' => $chat->messages()->with('sender')->latest()->paginate(50)
        ]);
    }

    public function start(Request $request)
    {
        $request->validate(['appointment_id' => 'required|exists:appointments,id']);
        
        $appointment = \App\Models\Appointment::findOrFail($request->appointment_id);
        
        // Ensure user is part of appointment
        $userId = $request->user()->id;
        if ((int) $userId !== (int) $appointment->patient_id && (int) $userId !== (int) $appointment->doctor_id) {
            return response()->json([
                'message' => 'Unauthorized access to appointment',
                'debug' => [
                    'user_id' => $userId,
                    'patient_id' => $appointment->patient_id,
                    'doctor_id' => $appointment->doctor_id
                ]
            ], 403);
        }

        // Find existing or create new
        $chat = \App\Models\Chat::firstOrCreate(
            ['appointment_id' => $appointment->id],
            [
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'status' => 'pending'
            ]
        );

        if ($chat->status === 'pending') {
            // Activate on first access (or you could wait for first message)
            // Ideally we wait for first message to start timer, but prompt implies simple flow.
            // Let's keep it pending until first message.
        }

        return response()->json($chat);
    }

    public function sendMessage(Request $request, $id)
    {
        $userId = $request->user()->id;
        $chat = \App\Models\Chat::findOrFail($id);

        if ((int) $chat->patient_id !== (int) $userId && (int) $chat->doctor_id !== (int) $userId) {
            abort(403);
        }

        // Time Check: Exact start time enforcement for appointment-linked chats
        if ($chat->appointment_id) {
            $appointment = \App\Models\Appointment::find($chat->appointment_id);
            if ($appointment) {
                $now = now();
                $apptTime = \Carbon\Carbon::parse($appointment->appointment_date->format('Y-m-d') . ' ' . $appointment->start_time);
                if ($now->lt($apptTime)) {
                    return response()->json([
                        'message' => 'Chat will be available at the scheduled appointment time: ' . $apptTime->format('h:i A')
                    ], 403);
                }
            }
        }

        // Start timer on first message if needed
        if (!$chat->started_at) {
            $chat->update([
                'started_at' => now(),
                'expires_at' => now()->addHours(5),
                'status' => 'active'
            ]);
        }

        // Check if expired
        if ($chat->is_expired || $chat->status === 'expired') {
            return response()->json(['message' => 'Chat session has expired'], 403);
        }

        $request->validate(['message' => 'required|string|max:1000']);

        $message = $chat->messages()->create([
            'sender_id' => $userId,
            'message' => strip_tags($request->message),
        ]);

        $chat->touch(); // Update updated_at to move to top of list

        return response()->json($message->load('sender'));
    }

    public function markRead(Request $request, $id)
    {
        $chat = \App\Models\Chat::findOrFail($id);
        // Mark all messages not from me as read
        $chat->messages()
            ->where('sender_id', '!=', $request->user()->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
            
        return response()->json(['message' => 'Marked as read']);
    }
}
