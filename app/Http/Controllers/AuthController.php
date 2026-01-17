<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $validated = $request->validate([
            'firstName' => 'required|string|max:255',
            'lastName' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8',
            'role' => 'required|string|in:patient,doctor,nurse',
            'otp' => 'nullable|string', // Add OTP validation
            // Patient specific validations
            'dob' => 'nullable|date',
            'gender' => 'nullable|string',
            'phone' => 'nullable|string',
            'address' => 'nullable|string',
            'city' => 'nullable|string',
            'state' => 'nullable|string',
            'country' => 'nullable|string',
            'zipCode' => 'nullable|string',
            'bloodGroup' => 'nullable|string',
            'allergies' => 'nullable|string',
            'conditions' => 'nullable|string',
            'emergencyName' => 'nullable|string',
            'emergencyPhone' => 'nullable|string',
            'virtualOnly' => 'boolean'
        ]);

        // Verify OTP if provided (strictly enforcing it would be better, but optional for backward comp if needed)
        // Check if we have a verified OTP in cache KEY
        if ($request->has('otp')) {
             $cachedOtp = \Illuminate\Support\Facades\Cache::get('otp_' . $validated['email']);
             if (!$cachedOtp || $cachedOtp !== $request->otp) {
                  throw ValidationException::withMessages([
                      'otp' => ['Invalid or expired verification code.'],
                  ]);
             }
             // Clear OTP
             \Illuminate\Support\Facades\Cache::forget('otp_' . $validated['email']);
        }

        $user = User::create([
            'name' => $validated['firstName'] . ' ' . $validated['lastName'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        if ($request->has('otp')) {
             $user->markEmailAsVerified();
        }

        if ($validated['role'] === 'patient') {
            Patient::create([
                'user_id' => $user->id,
                'dob' => $validated['dob'] ?? null,
                'gender' => $validated['gender'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'blood_group' => $validated['bloodGroup'] ?? null,
                'allergies' => $validated['allergies'] ?? null,
                'conditions' => $validated['conditions'] ?? null,
                'emergency_name' => $validated['emergencyName'] ?? null,
                'emergency_phone' => $validated['emergencyPhone'] ?? null,
                'address' => $validated['address'] ?? null,
                'city' => $validated['city'] ?? null,
                'state' => $validated['state'] ?? null,
                'country' => $validated['country'] ?? null,
                'zip_code' => $validated['zipCode'] ?? null,
                'virtual_only' => $validated['virtualOnly'] ?? false,
            ]);
        }

        // Create token
        $token = $user->createToken('auth_token')->plainTextToken;

        // Only send registered event if email was NOT verified via OTP
        if (!$user->hasVerifiedEmail()) {
             event(new Registered($user));
        }

        return response()->json([
            'message' => 'Registration successful',
            'user' => $user,
            'token' => $token
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials do not match our records.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $user,
            'token' => $token
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function sendOtp(Request $request)
    {
        $request->validate(['email' => 'required|email|unique:users,email']);

        $otp = rand(100000, 999999);
        // Cache for 20 minutes
        \Illuminate\Support\Facades\Cache::put('otp_' . $request->email, (string)$otp, 1200);
        
        // Send Email
        try {
            \Illuminate\Support\Facades\Mail::to($request->email)->send(new \App\Mail\OtpMail($otp));
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('Mail sending failed: ' . $e->getMessage());
            // Proceed anyway, but maybe return the OTP for dev purposes
            return response()->json([
                'message' => 'OTP generated (Email failed)',
                'debug_otp' => $otp // REMOVE IN PRODUCTION
            ]);
        }

        return response()->json(['message' => 'OTP sent successfully']);
    }

    public function verifyOtp(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string'
        ]);

        $cachedOtp = \Illuminate\Support\Facades\Cache::get('otp_' . $request->email);

        if ($cachedOtp && $cachedOtp === $request->code) {
             return response()->json(['message' => 'OTP verified']);
        }

        return response()->json(['message' => 'Invalid or expired OTP'], 400);
    }

    public function forgotPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink($request->only('email'));

        return $status === Password::RESET_LINK_SENT
            ? response()->json(['status' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|confirmed|min:8',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password)
                ])->setRememberToken(Str::random(60));

                $user->save();

                event(new \Illuminate\Auth\Events\PasswordReset($user));
            }
        );

        return $status === Password::PASSWORD_RESET
            ? response()->json(['status' => __($status)])
            : response()->json(['message' => __($status)], 400);
    }

    public function verifyEmail(Request $request)
    {
        $user = User::find($request->route('id'));

        if (! $user) {
            return redirect(config('app.frontend_url', 'http://localhost:5173') . '/login?error=invalid_user');
        }

        if (! hash_equals((string) $request->route('hash'), sha1($user->getEmailForVerification()))) {
             return redirect(config('app.frontend_url', 'http://localhost:5173') . '/login?error=invalid_hash');
        }

        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url', 'http://localhost:5173') . '/login?verified=1');
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect(config('app.frontend_url', 'http://localhost:5173') . '/login?verified=1');
    }

    public function resendVerificationEmail(Request $request)
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json(['message' => 'Email already verified']);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json(['status' => 'verification-link-sent']);
    }
}
