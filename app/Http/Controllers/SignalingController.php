<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\Appointment;

class SignalingController extends Controller
{
    /**
     * Join an appointment call room
     */
    public function join(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        $userId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));
        $role = $request->input('role', 'patient');
        $receiverId = $request->input('receiverId') ?: $request->input('receiver_id') ?: $request->query('receiverId') ?: $request->query('receiver_id');

        if (!$appointmentId || !$userId) {
            return response()->json(['status' => 'error', 'message' => 'Missing appointmentId or userId'], 400);
        }

        $userId = (int)$userId;
        $appointmentId = (int)$appointmentId;

        // Reset ended state on new join
        Cache::forget("signaling_ended_{$appointmentId}");

        $roomKey = "signaling_room_{$appointmentId}";
        $participants = Cache::get($roomKey, []);

        $participants[$userId] = [
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now()->timestamp,
            'last_seen' => microtime(true)
        ];

        Cache::put($roomKey, $participants, now()->addMinutes(30));

        // Resolve doctor & patient from Appointment model if available
        $patientUserId = null;
        $doctorUserId = null;
        try {
            $appointment = Appointment::find($appointmentId);
            if ($appointment) {
                $patientUserId = (int)$appointment->patient_id;
                $doctorUserId = (int)$appointment->doctor_id;
            }
        } catch (\Exception $e) {
            // Fallback silently if db query fails
        }

        // If receiverId passed or resolved, alert receiver personal incoming queue
        $targetAlertId = $receiverId ?: ($userId === $doctorUserId ? $patientUserId : $doctorUserId);
        if ($targetAlertId && (int)$targetAlertId !== $userId) {
            $alertKey = "signaling_incoming_{$targetAlertId}";
            Cache::put($alertKey, [
                'appointment_id' => $appointmentId,
                'sender_id' => $userId,
                'doctor_name' => $request->input('doctor_name', 'Doctor'),
                'time' => now()->timestamp
            ], now()->addMinutes(3));
        }

        $isReady = count($participants) >= 2;

        return response()->json([
            'status' => 'ok',
            'room' => $roomKey,
            'participants_count' => count($participants),
            'ready' => $isReady,
            'server_time' => microtime(true)
        ]);
    }

    /**
     * Send WebRTC signal (offer, answer, candidate)
     */
    public function signal(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        $senderId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));
        $targetId = $request->input('target') ?: $request->input('receiverId') ?: $request->input('receiver_id') ?: $request->query('target') ?: $request->query('receiverId') ?: $request->query('receiver_id');
        $signal = $request->input('signal');

        if (!$appointmentId || !$signal) {
            return response()->json(['status' => 'error', 'message' => 'Missing signal parameters'], 400);
        }

        $appointmentId = (int)$appointmentId;
        $senderId = (int)$senderId;

        // Parse signal if delivered as string
        if (is_string($signal)) {
            $decoded = json_decode($signal, true);
            if ($decoded) {
                $signal = $decoded;
            }
        }

        $signalItem = [
            'id' => uniqid('sig_', true),
            'sender_id' => $senderId,
            'target_id' => $targetId ? (int)$targetId : null,
            'signal' => $signal,
            'timestamp' => microtime(true)
        ];

        // 1. Store in appointment room broadcast queue
        $roomQueueKey = "signaling_room_queue_{$appointmentId}";
        $roomQueue = Cache::get($roomQueueKey, []);
        $roomQueue[] = $signalItem;

        // Keep at most 100 signals in room history
        if (count($roomQueue) > 100) {
            $roomQueue = array_slice($roomQueue, -100);
        }
        Cache::put($roomQueueKey, $roomQueue, now()->addMinutes(15));

        // 2. Also push to target-specific queue if specified for backwards compatibility
        if ($targetId) {
            $legacyKey = "signaling_queue_{$appointmentId}_{$targetId}";
            $legacyQueue = Cache::get($legacyKey, []);
            $legacyQueue[] = $signalItem;
            Cache::put($legacyKey, $legacyQueue, now()->addMinutes(5));
        }

        return response()->json([
            'status' => 'sent',
            'id' => $signalItem['id'],
            'timestamp' => $signalItem['timestamp']
        ]);
    }

    /**
     * Poll for pending WebRTC signals and room status
     */
    public function poll(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        $userId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));
        $lastTimestamp = (float)($request->input('last_timestamp') ?: $request->query('last_timestamp') ?: 0);

        if (!$appointmentId || !$userId) {
            return response()->json(['status' => 'error', 'message' => 'Missing appointmentId or userId'], 400);
        }

        $appointmentId = (int)$appointmentId;
        $userId = (int)$userId;
        $now = microtime(true);

        // 1. Update heartbeat in room
        $roomKey = "signaling_room_{$appointmentId}";
        $participants = Cache::get($roomKey, []);
        if (isset($participants[$userId])) {
            $participants[$userId]['last_seen'] = $now;
            Cache::put($roomKey, $participants, now()->addMinutes(30));
        }

        // Clean out stale participants (>45s inactive)
        $activeCount = 0;
        foreach ($participants as $pid => $pdata) {
            if (($now - ($pdata['last_seen'] ?? 0)) < 45) {
                $activeCount++;
            }
        }

        // 2. Fetch room signals where sender != current userId and timestamp > lastTimestamp
        $roomQueueKey = "signaling_room_queue_{$appointmentId}";
        $roomQueue = Cache::get($roomQueueKey, []);
        $pendingSignals = [];

        foreach ($roomQueue as $item) {
            $senderId = (int)($item['sender_id'] ?? 0);
            $sigTime = (float)($item['timestamp'] ?? 0);

            // Filter out own signals and already-consumed signals
            if ($senderId !== $userId && $sigTime > ($lastTimestamp + 0.000001)) {
                $pendingSignals[] = $item;
            }
        }

        // 3. Clear legacy queue if exists
        $legacyKey = "signaling_queue_{$appointmentId}_{$userId}";
        $legacyQueue = Cache::get($legacyKey, []);
        if (!empty($legacyQueue)) {
            Cache::forget($legacyKey);
            foreach ($legacyQueue as $lItem) {
                if (!in_array($lItem['id'] ?? '', array_column($pendingSignals, 'id'))) {
                    $pendingSignals[] = $lItem;
                }
            }
        }

        // 4. Check if call ended
        $endedKey = "signaling_ended_{$appointmentId}";
        $ended = Cache::get($endedKey, false);

        return response()->json([
            'status' => 'ok',
            'signals' => $pendingSignals,
            'ready' => $activeCount >= 2,
            'participants_count' => $activeCount,
            'ended' => (bool)$ended,
            'server_time' => $now
        ]);
    }

    /**
     * Poll for global incoming call for a patient
     */
    public function checkIncoming(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));
        if (!$userId) {
            return response()->json(['status' => 'error'], 400);
        }

        $userId = (int)$userId;
        $alertKey = "signaling_incoming_{$userId}";
        $incoming = Cache::get($alertKey);

        if (!$incoming) {
            return response()->json(['status' => 'ok', 'incoming' => null]);
        }

        // Validate that caller is still actively in the call room
        $appointmentId = (int)($incoming['appointment_id'] ?? 0);
        $senderId = (int)($incoming['sender_id'] ?? 0);

        if ($appointmentId > 0) {
            $ended = Cache::get("signaling_ended_{$appointmentId}", false);
            $roomKey = "signaling_room_{$appointmentId}";
            $participants = Cache::get($roomKey, []);

            $senderActive = false;
            if ($senderId > 0 && isset($participants[$senderId])) {
                $lastSeen = (float)($participants[$senderId]['last_seen'] ?? 0);
                if ((microtime(true) - $lastSeen) < 25) {
                    $senderActive = true;
                }
            }

            if ($ended || !$senderActive) {
                Cache::forget($alertKey);
                return response()->json(['status' => 'ok', 'incoming' => null]);
            }
        }

        return response()->json([
            'status' => 'ok',
            'incoming' => $incoming
        ]);
    }

    /**
     * Dismiss / clear pending incoming call alert
     */
    public function dismissIncoming(Request $request)
    {
        $userId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));
        if ($userId) {
            Cache::forget("signaling_incoming_{(int)$userId}");
        }
        return response()->json(['status' => 'ok']);
    }

    /**
     * End call
     */
    public function end(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        if ($appointmentId) {
            $appointmentId = (int)$appointmentId;
            $endedKey = "signaling_ended_{$appointmentId}";
            Cache::put($endedKey, true, now()->addMinutes(5));

            Cache::forget("signaling_room_{$appointmentId}");
            Cache::forget("signaling_room_queue_{$appointmentId}");

            // Clear any alerts linked to this appointment
            try {
                $appointment = Appointment::find($appointmentId);
                if ($appointment) {
                    Cache::forget("signaling_incoming_{$appointment->patient_id}");
                    Cache::forget("signaling_incoming_{$appointment->doctor_id}");
                }
            } catch (\Exception $e) {}
        }

        return response()->json(['status' => 'ended']);
    }
}


