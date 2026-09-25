<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        $roomKey = "signaling_room_{$appointmentId}";
        $participants = Cache::get($roomKey, []);

        $participants[$userId] = [
            'user_id' => $userId,
            'role' => $role,
            'joined_at' => now()->timestamp,
            'last_seen' => now()->timestamp
        ];

        Cache::put($roomKey, $participants, now()->addMinutes(30));

        // If target receiver exists, alert receiver personal queue
        if ($receiverId) {
            $alertKey = "signaling_incoming_{$receiverId}";
            Cache::put($alertKey, [
                'appointment_id' => $appointmentId,
                'sender_id' => $userId,
                'doctor_name' => $request->input('doctor_name', 'Doctor'),
                'time' => now()->timestamp
            ], now()->addMinutes(2));
        }

        $isReady = count($participants) >= 2;

        return response()->json([
            'status' => 'ok',
            'room' => $roomKey,
            'participants_count' => count($participants),
            'ready' => $isReady
        ]);
    }

    /**
     * Send WebRTC signal (offer, answer, candidate)
     */
    public function signal(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        $targetId = $request->input('target') ?: $request->input('receiverId') ?: $request->input('receiver_id') ?: $request->query('target') ?: $request->query('receiverId') ?: $request->query('receiver_id');
        $signal = $request->input('signal');
        $senderId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));

        if (!$appointmentId || !$targetId || !$signal) {
            return response()->json(['status' => 'error', 'message' => 'Missing signal parameters'], 400);
        }

        $queueKey = "signaling_queue_{$appointmentId}_{$targetId}";
        $queue = Cache::get($queueKey, []);

        $queue[] = [
            'sender_id' => $senderId,
            'signal' => $signal,
            'timestamp' => microtime(true)
        ];

        Cache::put($queueKey, $queue, now()->addMinutes(5));

        return response()->json(['status' => 'sent', 'count' => count($queue)]);
    }

    /**
     * Poll for pending WebRTC signals and room status
     */
    public function poll(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        $userId = $request->user() ? $request->user()->id : ($request->input('userId') ?: $request->input('user_id') ?: $request->query('userId') ?: $request->query('user_id'));

        if (!$appointmentId || !$userId) {
            return response()->json(['status' => 'error', 'message' => 'Missing appointmentId or userId'], 400);
        }

        // 1. Update heartbeat in room
        $roomKey = "signaling_room_{$appointmentId}";
        $participants = Cache::get($roomKey, []);
        if (isset($participants[$userId])) {
            $participants[$userId]['last_seen'] = now()->timestamp;
            Cache::put($roomKey, $participants, now()->addMinutes(30));
        }

        // Clean out stale participants (>45s inactive)
        $activeCount = 0;
        $now = now()->timestamp;
        foreach ($participants as $pid => $pdata) {
            if (($now - ($pdata['last_seen'] ?? 0)) < 45) {
                $activeCount++;
            }
        }

        // 2. Fetch queued signals for this user
        $queueKey = "signaling_queue_{$appointmentId}_{$userId}";
        $signals = Cache::get($queueKey, []);
        if (!empty($signals)) {
            Cache::forget($queueKey);
        }

        // 3. Check if call ended
        $endedKey = "signaling_ended_{$appointmentId}";
        $ended = Cache::get($endedKey, false);

        return response()->json([
            'status' => 'ok',
            'signals' => $signals,
            'ready' => $activeCount >= 2,
            'participants_count' => $activeCount,
            'ended' => (bool)$ended
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

        $alertKey = "signaling_incoming_{$userId}";
        $incoming = Cache::get($alertKey);

        return response()->json([
            'status' => 'ok',
            'incoming' => $incoming
        ]);
    }

    /**
     * End call
     */
    public function end(Request $request)
    {
        $appointmentId = $request->input('appointment_id') ?: $request->input('appointmentId') ?: $request->query('appointment_id') ?: $request->query('appointmentId');
        if ($appointmentId) {
            $endedKey = "signaling_ended_{$appointmentId}";
            Cache::put($endedKey, true, now()->addMinutes(5));

            $roomKey = "signaling_room_{$appointmentId}";
            Cache::forget($roomKey);
        }

        return response()->json(['status' => 'ended']);
    }
}
