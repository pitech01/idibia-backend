<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $user->load('patient');
        return response()->json($user);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => ['required', 'email', Rule::unique('users')->ignore($user->id)],
            'phone' => 'nullable|string|max:20',
            'dob' => 'nullable|date',
            'gender' => 'nullable|string|in:Male,Female,Other',
            'address' => 'nullable|string',
            'blood_group' => 'nullable|string',
            'emergency_name' => 'nullable|string',
            'emergency_phone' => 'nullable|string',
            'emergency_relationship' => 'nullable|string',
        ]);

        // Update User
        $user->update([
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'email' => $validated['email'],
        ]);

        // Update or Create Patient profile
        $user->patient()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => $validated['phone'] ?? null,
                'dob' => $validated['dob'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'address' => $validated['address'] ?? null,
                'blood_group' => $validated['blood_group'] ?? null,
                'emergency_name' => $validated['emergency_name'] ?? null,
                'emergency_phone' => $validated['emergency_phone'] ?? null,
                'emergency_relationship' => $validated['emergency_relationship'] ?? null,
                'is_completed' => true,
            ]
        );
        
        // Refresh to get updated data
        $user->load('patient');

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $user
        ]);
    }
    
    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|current_password',
            'new_password' => 'required|confirmed|min:8',
        ]);
        
        $request->user()->update([
            'password' => Hash::make($request->new_password),
        ]);
        
        return response()->json(['message' => 'Password updated successfully']);
    }
}
