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
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'zip_code' => 'nullable|string',
            'blood_group' => 'nullable|string',
            'allergies' => 'nullable|string',
            'conditions' => 'nullable|string',
            'emergency_name' => 'nullable|string',
            'emergency_phone' => 'nullable|string',
            'emergency_relationship' => 'nullable|string',
            'emergency_address' => 'nullable|string',
            'virtual_only' => 'nullable|boolean',
            'settings' => 'nullable|array',
        ]);

        // Update User
        $userUpdates = [
            'name' => $validated['first_name'] . ' ' . $validated['last_name'],
            'email' => $validated['email'],
        ];

        if (isset($validated['settings'])) {
            $userUpdates['settings'] = array_merge($user->settings ?: [], $validated['settings']);
        }

        $user->update($userUpdates);

        // Update or Create Patient profile
        $user->patient()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'phone' => $validated['phone'] ?? null,
                'dob' => $validated['dob'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'country' => $validated['country'] ?? null,
                'zip_code' => $validated['zip_code'] ?? null,
                'blood_group' => $validated['blood_group'] ?? null,
                'allergies' => $validated['allergies'] ?? null,
                'conditions' => $validated['conditions'] ?? null,
                'emergency_name' => $validated['emergency_name'] ?? null,
                'emergency_phone' => $validated['emergency_phone'] ?? null,
                'emergency_relationship' => $validated['emergency_relationship'] ?? null,
                'virtual_only' => $validated['virtual_only'] ?? false,
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

    public function updateSettings(Request $request)
    {
        $user = $request->user();
        $validated = $request->validate([
            'settings' => 'required|array',
        ]);

        $current = $user->settings ?: [];
        $updated = array_merge($current, $validated['settings']);

        $user->update([
            'settings' => $updated,
        ]);

        return response()->json([
            'message' => 'Settings updated successfully',
            'settings' => $user->settings,
        ]);
    }

    public function updateAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif,webp,svg|max:15360', // 15MB limit
        ]);

        $user = $request->user();

        if ($request->hasFile('avatar')) {
            $path = $request->file('avatar')->store('avatars', 'public');
            $filename = basename($path);
            $fullUrl = url('/api/avatar/' . $filename);
            
            $user->update(['avatar' => $fullUrl]);

            return response()->json([
                'message' => 'Avatar updated successfully',
                'avatar' => $fullUrl,
                'filename' => $filename,
                'path' => $path
            ]);
        }

        return response()->json(['message' => 'No file uploaded'], 400);
    }

    public function getAvatarFile($filename)
    {
        $filename = basename($filename);
        
        $candidates = [
            storage_path('app/public/avatars/' . $filename),
            storage_path('app/avatars/' . $filename),
            public_path('storage/avatars/' . $filename),
            storage_path('app/public/' . $filename),
            storage_path('app/' . $filename),
        ];

        foreach ($candidates as $file) {
            if (file_exists($file) && !is_dir($file)) {
                $mimeType = @mime_content_type($file) ?: 'image/jpeg';
                return response()->file($file, [
                    'Content-Type' => $mimeType,
                    'Access-Control-Allow-Origin' => '*',
                    'Access-Control-Allow-Methods' => 'GET, OPTIONS',
                    'Access-Control-Allow-Headers' => '*',
                    'Cache-Control' => 'public, max-age=86400',
                ]);
            }
        }

        return response()->json(['message' => 'Avatar file not found'], 404);
    }

    public function getUserAvatar($id)
    {
        $user = \App\Models\User::find($id);
        if (!$user || !$user->avatar) {
            return response()->json(['message' => 'Avatar not found'], 404);
        }

        $filename = basename($user->avatar);
        return $this->getAvatarFile($filename);
    }
}

