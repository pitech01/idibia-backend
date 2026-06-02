<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SuperAdminController extends Controller
{
    private function ensureSuperAdmin(Request $request)
    {
        if ($request->user()->role !== 'super-admin') {
            abort(403, 'Unauthorized action. Super Admin access required.');
        }
    }

    public function getAllUsers(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $users = User::all();
        return response()->json($users);
    }

    public function createAdminUser(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|in:admin,super-admin'
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $request->role,
            'email_verified_at' => now(),
        ]);

        return response()->json(['message' => 'Admin user created successfully', 'user' => $user], 201);
    }

    public function deleteUser(Request $request, $id)
    {
        $this->ensureSuperAdmin($request);
        $user = User::findOrFail($id);
        
        // Prevent deleting yourself
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot delete your own account'], 400);
        }

        $user->delete();
        return response()->json(['message' => 'User deleted successfully']);
    }

    public function adjustCredits(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'amount' => 'required|numeric',
            'type' => 'required|in:add,reset,adjust',
            'description' => 'nullable|string'
        ]);

        $user = User::findOrFail($request->user_id);
        
        if ($request->type === 'reset') {
            $user->credits = 0;
        } else {
            $user->credits += $request->amount;
        }
        
        $user->save();

        \App\Models\CreditTransaction::create([
            'user_id' => $user->id,
            'amount' => $request->amount,
            'type' => $request->type,
            'description' => $request->description ?? 'System adjustment'
        ]);

        return response()->json(['message' => 'Credits adjusted successfully', 'new_balance' => $user->credits]);
    }

    public function updateGlobalPool(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $request->validate([
            'amount' => 'required|numeric',
            'type' => 'required|in:add,set,reset'
        ]);

        $current = floatval(\App\Models\SystemConfig::get('global_credits', 0));
        
        if ($request->type === 'reset') {
            $new = 0;
        } elseif ($request->type === 'set') {
            $new = $request->amount;
        } else {
            $new = $current + $request->amount;
        }

        \App\Models\SystemConfig::set('global_credits', $new);

        \App\Models\CreditTransaction::create([
            'user_id' => $request->user()->id,
            'amount' => $new - $current,
            'type' => 'adjust',
            'description' => 'Global Pool Adjustment: ' . $request->type
        ]);

        return response()->json(['message' => 'Global credit pool updated', 'new_balance' => $new]);
    }

    public function getCreditTransactions(Request $request)
    {
        $this->ensureSuperAdmin($request);
        $transactions = \App\Models\CreditTransaction::with(['user', 'appointment'])
            ->orderBy('created_at', 'desc')
            ->limit(100)
            ->get();
        return response()->json($transactions);
    }

    public function getSystemStats(Request $request)
    {
        $this->ensureSuperAdmin($request);
        
        return response()->json([
            'total_users' => User::count(),
            'admins' => User::where('role', 'admin')->count(),
            'super_admins' => User::where('role', 'super-admin')->count(),
            'active_sessions' => \DB::table('personal_access_tokens')->count(),
            'global_credits' => floatval(\App\Models\SystemConfig::get('global_credits', 0)),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_os' => PHP_OS,
        ]);
    }
}
