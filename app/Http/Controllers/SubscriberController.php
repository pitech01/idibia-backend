<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SubscriberController extends Controller
{
    /**
     * Public: Subscribe to newsletter
     */
    public function subscribe(Request $request)
    {
        $request->validate([
            'email' => 'required|email|unique:subscribers,email',
        ], [
            'email.unique' => 'You are already subscribed to our newsletter!',
        ]);

        Subscriber::create([
            'email' => $request->email,
            'status' => 'active',
        ]);

        return response()->json(['message' => 'Thank you for subscribing to our newsletter!']);
    }

    /**
     * Admin: List all subscribers
     */
    public function index()
    {
        return response()->json(Subscriber::orderBy('created_at', 'desc')->get());
    }

    /**
     * Admin: Delete a subscriber
     */
    public function destroy($id)
    {
        $subscriber = Subscriber::findOrFail($id);
        $subscriber->delete();

        return response()->json(['message' => 'Subscriber removed successfully.']);
    }

    /**
     * Admin: Send bulk email to subscribers
     */
    public function sendEmail(Request $request)
    {
        $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $subscribers = Subscriber::where('status', 'active')->get();

        foreach ($subscribers as $subscriber) {
            try {
                Mail::to($subscriber->email)->send(new \App\Mail\Newsletter($request->subject, $request->message));
            } catch (\Exception $e) {
                \Log::error("Failed to send newsletter to {$subscriber->email}: " . $e->getMessage());
            }
        }

        return response()->json([
            'message' => 'Newsletter blast sent successfully to ' . $subscribers->count() . ' subscribers.',
        ]);
    }
}
