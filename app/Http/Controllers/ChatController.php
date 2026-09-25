<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Chat;
use App\Models\Message;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ChatController extends Controller
{
    /**
     * List all unique conversations for the current user.
     * Consolidates duplicate chat sessions per patient-doctor pair.
     */
    public function index(Request $request)
    {
        $userId = $request->user()->id;
        
        $chats = Chat::with([
                'latestMessage',
                'patient',
                'doctor.doctor',
                'appointment'
            ])
            ->where(function ($query) use ($userId) {
                $query->where('patient_id', $userId)
                      ->orWhere('doctor_id', $userId);
            })
            ->withCount(['messages as unread_count' => function ($query) use ($userId) {
                $query->where('sender_id', '!=', $userId)->whereNull('read_at');
            }])
            ->latest('updated_at')
            ->get();

        // Group / deduplicate by patient_id & doctor_id pair so each user only has 1 clean conversation thread
        $uniqueChats = $chats->unique(function ($chat) {
            return $chat->patient_id . '_' . $chat->doctor_id;
        })->values();

        return response()->json($uniqueChats);
    }

    /**
     * Get specific chat details and paginated message history.
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user->id;

        $chat = Chat::with(['patient', 'doctor.doctor', 'appointment'])
            ->where('id', $id)
            ->where(function ($query) use ($userId, $user) {
                if (in_array($user->role, ['admin', 'super_admin'])) {
                    return; // Admins can view any chat
                }
                $query->where('patient_id', $userId)
                      ->orWhere('doctor_id', $userId);
            })
            ->firstOrFail();

        // Auto-activate pending chats when opened
        if ($chat->status === 'pending') {
            $chat->update([
                'status' => 'active',
                'started_at' => $chat->started_at ?? now(),
                'expires_at' => now()->addDays(7)
            ]);
        }

        return response()->json([
            'chat' => $chat,
            'messages' => $chat->messages()->with('sender')->latest()->paginate(100)
        ]);
    }

    /**
     * Start or locate existing chat linked to an appointment.
     * Reuses and reactivates existing chat thread between patient and doctor.
     */
    public function start(Request $request)
    {
        $request->validate(['appointment_id' => 'required|exists:appointments,id']);
        
        $appointment = Appointment::findOrFail($request->appointment_id);
        $user = $request->user();
        $userId = $user->id;

        // Ensure user is authorized
        if ((int) $userId !== (int) $appointment->patient_id && 
            (int) $userId !== (int) $appointment->doctor_id && 
            !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized access to appointment'], 403);
        }

        // Check if a conversation thread already exists between this patient and doctor
        $chat = Chat::where('patient_id', $appointment->patient_id)
            ->where('doctor_id', $appointment->doctor_id)
            ->latest('updated_at')
            ->first();

        if ($chat) {
            // Update to latest appointment and reactivate conversation window
            $chat->update([
                'appointment_id' => $appointment->id,
                'status' => 'active',
                'started_at' => $chat->started_at ?? now(),
                'expires_at' => now()->addDays(7),
            ]);
            $chat->touch();
        } else {
            // Create single unified conversation
            $chat = Chat::create([
                'appointment_id' => $appointment->id,
                'patient_id' => $appointment->patient_id,
                'doctor_id' => $appointment->doctor_id,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => now()->addDays(7),
            ]);
        }

        return response()->json($chat->load(['patient', 'doctor.doctor', 'appointment']));
    }

    /**
     * Start or find a direct chat between current user and target user.
     */
    public function startDirect(Request $request)
    {
        $request->validate(['target_user_id' => 'required|exists:users,id']);
        
        $currentUser = $request->user();
        $targetUser = User::findOrFail($request->target_user_id);

        if ((int) $currentUser->id === (int) $targetUser->id) {
            return response()->json(['message' => 'Cannot start a chat with yourself'], 422);
        }

        // Determine patient and doctor IDs
        if ($currentUser->role === 'doctor') {
            $doctorId = $currentUser->id;
            $patientId = $targetUser->id;
        } elseif ($targetUser->role === 'doctor') {
            $doctorId = $targetUser->id;
            $patientId = $currentUser->id;
        } else {
            // Default: current user as patient, target as doctor or vice versa
            $patientId = $currentUser->id;
            $doctorId = $targetUser->id;
        }

        // Check if a conversation already exists
        $chat = Chat::where('patient_id', $patientId)
            ->where('doctor_id', $doctorId)
            ->latest('updated_at')
            ->first();

        if ($chat) {
            $chat->update([
                'status' => 'active',
                'expires_at' => now()->addDays(14)
            ]);
            $chat->touch();
        } else {
            // Find latest appointment if any between them
            $latestAppt = Appointment::where('patient_id', $patientId)
                ->where('doctor_id', $doctorId)
                ->latest()
                ->first();

            $chat = Chat::create([
                'appointment_id' => $latestAppt ? $latestAppt->id : null,
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'status' => 'active',
                'started_at' => now(),
                'expires_at' => now()->addDays(14),
            ]);
        }

        return response()->json($chat->load(['patient', 'doctor.doctor', 'appointment']));
    }

    /**
     * Send message within a chat session.
     */
    public function sendMessage(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user->id;
        $chat = Chat::findOrFail($id);

        if ((int) $chat->patient_id !== (int) $userId && 
            (int) $chat->doctor_id !== (int) $userId && 
            !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized to send messages in this chat'], 403);
        }

        $request->validate(['message' => 'required|string|max:2000']);

        // Keep chat active with a rolling 7-day window on communication
        $chat->update([
            'status' => 'active',
            'started_at' => $chat->started_at ?? now(),
            'expires_at' => now()->addDays(7),
        ]);

        $message = $chat->messages()->create([
            'sender_id' => $userId,
            'message' => strip_tags($request->message),
        ]);

        $chat->touch(); // Move to top of conversations list

        $loadedMessage = $message->load('sender');
        $receiverId = ((int) $chat->patient_id === (int) $userId) ? $chat->doctor_id : $chat->patient_id;

        $this->broadcastToSignaling('chat:message', [
            'chat_id' => $chat->id,
            'appointment_id' => $chat->appointment_id,
            'receiver_id' => $receiverId,
            'sender_id' => $userId,
            'message' => $loadedMessage,
        ]);

        return response()->json($loadedMessage);
    }

    /**
     * Mark unread messages in chat as read.
     */
    public function markRead(Request $request, $id)
    {
        $chat = Chat::findOrFail($id);
        $userId = $request->user()->id;

        $chat->messages()
            ->where('sender_id', '!=', $userId)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
            
        return response()->json(['message' => 'Marked as read']);
    }

    /**
     * Delete entire chat conversation and its messages.
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user->id;
        $chat = Chat::findOrFail($id);

        if ((int) $chat->patient_id !== (int) $userId && 
            (int) $chat->doctor_id !== (int) $userId && 
            !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized to delete this chat'], 403);
        }

        // Delete all messages in the chat
        $chat->messages()->delete();
        $chat->delete();

        return response()->json([
            'success' => true,
            'message' => 'Chat conversation deleted successfully'
        ]);
    }

    /**
     * Clear all messages in a chat while keeping the channel.
     */
    public function clearMessages(Request $request, $id)
    {
        $user = $request->user();
        $userId = $user->id;
        $chat = Chat::findOrFail($id);

        if ((int) $chat->patient_id !== (int) $userId && 
            (int) $chat->doctor_id !== (int) $userId && 
            !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized to clear messages in this chat'], 403);
        }

        $chat->messages()->delete();
        $chat->touch();

        return response()->json([
            'success' => true,
            'message' => 'Chat history cleared successfully'
        ]);
    }

    /**
     * Delete an individual message.
     */
    public function deleteMessage(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $userId = $user->id;
        $chat = Chat::findOrFail($id);
        $message = $chat->messages()->findOrFail($messageId);

        if ((int) $message->sender_id !== (int) $userId && !in_array($user->role, ['admin', 'super_admin'])) {
            return response()->json(['message' => 'Unauthorized to delete this message'], 403);
        }

        $message->delete();

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
    }

    private function broadcastToSignaling($event, $data)
    {
        $url = config('services.signaling.url', 'http://127.0.0.1:3000') . '/broadcast';
        try {
            Http::timeout(2)
                ->withoutVerifying()
                ->post($url, [
                    'event' => $event,
                    'data' => $data
                ]);
        } catch (\Exception $e) {
            Log::warning("Signaling chat broadcast failed: " . $e->getMessage());
        }
    }
}

