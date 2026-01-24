<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user()->load('patient');
})->middleware('auth:sanctum');

Route::get('/ping', function () {
    return response()->json(['message' => 'Pong', 'status' => 'ok', 'timestamp' => now()]);
});

Route::post('/register', [App\Http\Controllers\AuthController::class, 'register']);
Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/forgot-password', [App\Http\Controllers\AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [App\Http\Controllers\AuthController::class, 'resetPassword']);
Route::post('/otp/send', [App\Http\Controllers\AuthController::class, 'sendOtp']);
Route::post('/otp/verify', [App\Http\Controllers\AuthController::class, 'verifyOtp']);

Route::get('/email/verify/{id}/{hash}', [App\Http\Controllers\AuthController::class, 'verifyEmail'])->name('verification.verify');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::post('/email/verification-notification', [App\Http\Controllers\AuthController::class, 'resendVerificationEmail']);
    
    Route::get('/doctors', function () {
        return \App\Models\User::where('role', 'doctor')->get();
    });

    Route::get('/appointments', [App\Http\Controllers\AppointmentController::class, 'index']);
    Route::post('/appointments', [App\Http\Controllers\AppointmentController::class, 'store']);
    Route::post('/appointments/{id}/cancel', [App\Http\Controllers\AppointmentController::class, 'cancel']);

    Route::get('/medical-records', [App\Http\Controllers\MedicalRecordController::class, 'index']);
    Route::post('/medical-records', [App\Http\Controllers\MedicalRecordController::class, 'store']);

    Route::get('/posts', [App\Http\Controllers\PostController::class, 'index']);
    Route::get('/posts/{id}', [App\Http\Controllers\PostController::class, 'show']);
    
    Route::get('/notifications', [App\Http\Controllers\NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'show']);
    Route::put('/profile', [App\Http\Controllers\ProfileController::class, 'update']);
    Route::put('/profile/password', [App\Http\Controllers\ProfileController::class, 'updatePassword']);

    Route::get('/payments', [App\Http\Controllers\PaymentController::class, 'index']);
    Route::post('/payments/initialize', [App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::post('/payments/verify', [App\Http\Controllers\PaymentController::class, 'verify']);

    // Chat Routes
    Route::get('/chats', [App\Http\Controllers\ChatController::class, 'index']);
    Route::post('/chats/start', [App\Http\Controllers\ChatController::class, 'start']);
    Route::get('/chats/{id}', [App\Http\Controllers\ChatController::class, 'show']);
    Route::post('/chats/{id}/send', [App\Http\Controllers\ChatController::class, 'sendMessage']);
    Route::put('/chats/{id}/read', [App\Http\Controllers\ChatController::class, 'markRead']);

    Route::get('/patient/dashboard', [App\Http\Controllers\PatientDashboardController::class, 'index']);
    
    // Doctor Profile
    Route::post('/doctor/profile', [App\Http\Controllers\DoctorController::class, 'register']);
});
