<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        if ($user->role === 'admin') {
            // Admin sees all tickets
            $tickets = \App\Models\SupportTicket::with('user')->orderBy('created_at', 'desc')->get();
        } else {
            // User sees their tickets
            $tickets = \App\Models\SupportTicket::where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->get();
        }

        return response()->json($tickets);
    }

    public function store(Request $request)
    {
        $request->validate([
            'subject' => 'required|string',
            'message' => 'required|string',
            'category' => 'nullable|string',
            'priority' => 'nullable|in:low,medium,high',
        ]);

        $ticket = \App\Models\SupportTicket::create([
            'user_id' => $request->user()->id,
            'subject' => $request->subject,
            'message' => $request->message,
            'category' => $request->category,
            'priority' => $request->priority ?? 'low',
        ]);

        // Add the initial message as a message record too? 
        // Or just keep it as the ticket description.
        // Let's create an initial message as well for consistency in the thread view.
        \App\Models\SupportMessage::create([
            'support_ticket_id' => $ticket->id,
            'user_id' => $request->user()->id,
            'message' => $request->message,
        ]);

        return response()->json($ticket, 201);
    }

    public function show($id, Request $request)
    {
        $ticket = \App\Models\SupportTicket::with(['user', 'messages.sender'])->findOrFail($id);

        // Authorization
        if ($request->user()->role !== 'admin' && $ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json($ticket);
    }

    public function reply($id, Request $request)
    {
        $request->validate([
            'message' => 'required|string',
        ]);

        $ticket = \App\Models\SupportTicket::findOrFail($id);
        
        // Authorization
        if ($request->user()->role !== 'admin' && $ticket->user_id !== $request->user()->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $message = \App\Models\SupportMessage::create([
            'support_ticket_id' => $id,
            'user_id' => $request->user()->id,
            'message' => $request->message,
        ]);

        // If admin replies, change ticket status to 'in_progress'?
        // Or keep it simple.

        return response()->json($message, 201);
    }

    public function updateStatus($id, Request $request)
    {
        $request->validate([
            'status' => 'required|in:open,in_progress,resolved,closed',
        ]);

        $ticket = \App\Models\SupportTicket::findOrFail($id);
        
        // Only admin should change status ideally, or user can close their own ticket.
        if ($request->user()->role !== 'admin' && $request->status !== 'closed') {
             // Let's assume only admin manages status for now, or user can cancel/close.
             if ($ticket->user_id !== $request->user()->id) {
                 return response()->json(['message' => 'Unauthorized'], 403);
             }
        }

        $ticket->status = $request->status;
        $ticket->save();

        return response()->json($ticket);
    }
}
