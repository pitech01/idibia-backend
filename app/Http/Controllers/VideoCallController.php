<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CreditTransaction;
use App\Models\SystemConfig;
use Illuminate\Http\Request;

class VideoCallController extends Controller
{
    /**
     * Consume credits from the Global Pool during a video call.
     */
    public function consumeCredits(Request $request)
    {
        $request->validate([
            'appointment_id' => 'required|exists:appointments,id',
            'amount' => 'required|numeric|min:0'
        ]);

        $globalCredits = floatval(SystemConfig::get('global_credits', 0));

        if ($globalCredits < $request->amount) {
            return response()->json([
                'error' => 'Global credit pool exhausted',
                'global_credits' => $globalCredits,
                'terminate' => true
            ], 402);
        }

        $newBalance = $globalCredits - $request->amount;
        SystemConfig::set('global_credits', $newBalance);

        CreditTransaction::create([
            'user_id' => $request->user()->id,
            'amount' => -$request->amount,
            'type' => 'consume',
            'description' => 'System-wide video call usage (shared pool)',
            'appointment_id' => $request->appointment_id
        ]);

        return response()->json([
            'message' => 'Global credits consumed',
            'remaining_global_credits' => $newBalance
        ]);
    }

    /**
     * Check if global pool has minimum credits to start a call.
     */
    public function checkCredits(Request $request)
    {
        $globalCredits = floatval(SystemConfig::get('global_credits', 0));
        $minCredits = 1; // Minimum global credits to allow starting a call

        if ($globalCredits < $minCredits) {
            return response()->json([
                'eligible' => false,
                'message' => "The platform universal credit pool is exhausted. New calls are temporarily restricted.",
                'global_credits' => $globalCredits
            ]);
        }

        return response()->json([
            'eligible' => true,
            'global_credits' => $globalCredits
        ]);
    }
}
