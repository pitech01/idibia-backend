<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user()->load(['patient', 'doctor']);
})->middleware('auth:sanctum');

Route::get('/ping', function () {
    return response()->json(['message' => 'Pong', 'status' => 'ok', 'timestamp' => now()]);
});

Route::get('/fix-cache', [App\Http\Controllers\MaintenanceController::class, 'fixCache']);

Route::post('/register', [App\Http\Controllers\AuthController::class, 'register']);
Route::post('/login', [App\Http\Controllers\AuthController::class, 'login']);
Route::post('/2fa/verify', [App\Http\Controllers\AuthController::class, 'verify2FA']);
Route::post('/2fa/resend', [App\Http\Controllers\AuthController::class, 'resend2FA']);
Route::post('/forgot-password', [App\Http\Controllers\AuthController::class, 'forgotPassword']);
Route::post('/reset-password', [App\Http\Controllers\AuthController::class, 'resetPassword']);
Route::post('/otp/send', [App\Http\Controllers\AuthController::class, 'sendOtp']);
Route::post('/otp/verify', [App\Http\Controllers\AuthController::class, 'verifyOtp']);

Route::get('/email/verify/{id}/{hash}', [App\Http\Controllers\AuthController::class, 'verifyEmail'])->name('verification.verify');

Route::post('/webhooks/paystack', [App\Http\Controllers\PaymentController::class, 'handleWebhook']);
Route::get('/posts', [App\Http\Controllers\PostController::class, 'index']);
Route::get('/posts/{id}', [App\Http\Controllers\PostController::class, 'show']);
Route::post('/subscribe', [App\Http\Controllers\SubscriberController::class, 'subscribe']);
Route::get('/medical-records/{id}/file', [App\Http\Controllers\MedicalRecordController::class, 'getFile']);
Route::get('/medical-records/file/{id}', [App\Http\Controllers\MedicalRecordController::class, 'getFile']);
Route::get('/avatar/{filename}', [App\Http\Controllers\ProfileController::class, 'getAvatarFile']);
Route::get('/user-avatar/{id}', [App\Http\Controllers\ProfileController::class, 'getUserAvatar']);
Route::get('/doctors/{id}/document/{type}', [App\Http\Controllers\AdminController::class, 'getDoctorDocument']);
Route::get('/admin/doctors/{id}/document/{type}', [App\Http\Controllers\AdminController::class, 'getDoctorDocument']);
Route::get('/doctor-documents/{path}', [App\Http\Controllers\AdminController::class, 'getDocumentByPath'])->where('path', '.*');
Route::get('/storage/{path}', [App\Http\Controllers\AdminController::class, 'getDocumentByPath'])->where('path', '.*');

// WebRTC Signaling Fallback Routes (HTTP Long-Polling)
Route::post('/signaling/join', [App\Http\Controllers\SignalingController::class, 'join']);
Route::post('/signaling/signal', [App\Http\Controllers\SignalingController::class, 'signal']);
Route::get('/signaling/poll', [App\Http\Controllers\SignalingController::class, 'poll']);
Route::get('/signaling/check-incoming', [App\Http\Controllers\SignalingController::class, 'checkIncoming']);
Route::post('/signaling/dismiss-incoming', [App\Http\Controllers\SignalingController::class, 'dismissIncoming']);
Route::post('/signaling/end', [App\Http\Controllers\SignalingController::class, 'end']);


Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [App\Http\Controllers\AuthController::class, 'logout']);
    Route::post('/email/verification-notification', [App\Http\Controllers\AuthController::class, 'resendVerificationEmail']);
    
    Route::get('/doctors', function () {
        return \App\Models\User::where('role', 'doctor')
            ->where('status', 'active')
            ->whereHas('doctor', function($q) {
                $q->where('status', 'active');
            })
            ->with('doctor')
            ->get();
    });
    
    Route::get('/doctors/{id}/slots', [App\Http\Controllers\AppointmentController::class, 'getDoctorSlots']);


    Route::get('/appointments', [App\Http\Controllers\AppointmentController::class, 'index']);
    Route::post('/appointments', [App\Http\Controllers\AppointmentController::class, 'store']);
    Route::post('/appointments/{id}/cancel', [App\Http\Controllers\AppointmentController::class, 'cancel']);
    Route::post('/appointments/{id}/start', [App\Http\Controllers\AppointmentController::class, 'start']);
    Route::post('/appointments/{id}/start-call', [App\Http\Controllers\AppointmentController::class, 'startCall']);
    Route::post('/appointments/{id}/end-call', [App\Http\Controllers\AppointmentController::class, 'endCall']);
    Route::post('/appointments/{id}/complete', [App\Http\Controllers\AppointmentController::class, 'complete']);
    Route::post('/appointments/{id}/rate', [App\Http\Controllers\AppointmentController::class, 'rateDoctor']);

    // Prescriptions Routes
    Route::get('/prescriptions', [App\Http\Controllers\PrescriptionController::class, 'index']);
    Route::post('/prescriptions', [App\Http\Controllers\PrescriptionController::class, 'store']);
    Route::get('/prescriptions/{id}', [App\Http\Controllers\PrescriptionController::class, 'show']);
    Route::post('/prescriptions/{id}/pay', [App\Http\Controllers\PrescriptionController::class, 'pay']);

    Route::get('/medical-records', [App\Http\Controllers\MedicalRecordController::class, 'index']);
    Route::post('/medical-records', [App\Http\Controllers\MedicalRecordController::class, 'store']);

    
    Route::get('/notifications', [App\Http\Controllers\NotificationController::class, 'index']);
    Route::put('/notifications/{id}/read', [App\Http\Controllers\NotificationController::class, 'markAsRead']);
    Route::put('/notifications/read-all', [App\Http\Controllers\NotificationController::class, 'markAllAsRead']);
    
    Route::get('/profile', [App\Http\Controllers\ProfileController::class, 'show']);
    Route::put('/profile', [App\Http\Controllers\ProfileController::class, 'update']);
    Route::put('/profile/settings', [App\Http\Controllers\ProfileController::class, 'updateSettings']);
    Route::put('/profile/password', [App\Http\Controllers\ProfileController::class, 'updatePassword']);
    Route::post('/profile/avatar', [App\Http\Controllers\ProfileController::class, 'updateAvatar']);

    Route::get('/payments', [App\Http\Controllers\PaymentController::class, 'index']);
    Route::post('/payments/initialize', [App\Http\Controllers\PaymentController::class, 'initialize']);
    Route::post('/payments/verify', [App\Http\Controllers\PaymentController::class, 'verify']);
    Route::post('/payments/pay-appointment', [App\Http\Controllers\PaymentController::class, 'payForAppointment']);
    
    // New Paystack Appointment Routes
    Route::post('/payments/paystack/initialize', [App\Http\Controllers\PaymentController::class, 'initializePaystack']);
    Route::post('/payments/paystack/verify', [App\Http\Controllers\PaymentController::class, 'verifyPaystack']);

    Route::get('/payment-methods', [App\Http\Controllers\PaymentController::class, 'getPaymentMethods']);
    Route::delete('/payment-methods/{id}', [App\Http\Controllers\PaymentController::class, 'deletePaymentMethod']);
    Route::put('/payment-methods/{id}/default', [App\Http\Controllers\PaymentController::class, 'setDefaultPaymentMethod']);

    // Chat Routes
    Route::get('/chats', [App\Http\Controllers\ChatController::class, 'index']);
    Route::post('/chats/start', [App\Http\Controllers\ChatController::class, 'start']);
    Route::post('/chats/start-direct', [App\Http\Controllers\ChatController::class, 'startDirect']);
    Route::get('/chats/{id}', [App\Http\Controllers\ChatController::class, 'show']);
    Route::post('/chats/{id}/send', [App\Http\Controllers\ChatController::class, 'sendMessage']);
    Route::put('/chats/{id}/read', [App\Http\Controllers\ChatController::class, 'markRead']);
    Route::delete('/chats/{id}', [App\Http\Controllers\ChatController::class, 'destroy']);
    Route::post('/chats/{id}/clear', [App\Http\Controllers\ChatController::class, 'clearMessages']);
    Route::delete('/chats/{id}/messages/{messageId}', [App\Http\Controllers\ChatController::class, 'deleteMessage']);

    // Support Routes
    Route::get('/support', [App\Http\Controllers\SupportController::class, 'index']);
    Route::post('/support', [App\Http\Controllers\SupportController::class, 'store']);
    Route::get('/support/{id}', [App\Http\Controllers\SupportController::class, 'show']);
    Route::post('/support/{id}/reply', [App\Http\Controllers\SupportController::class, 'reply']);
    Route::put('/support/{id}/status', [App\Http\Controllers\SupportController::class, 'updateStatus']);

    Route::get('/patient/dashboard', [App\Http\Controllers\PatientDashboardController::class, 'index']);
    
    // Doctor Profile
    Route::post('/doctor/profile', [App\Http\Controllers\DoctorController::class, 'register']);
    Route::get('/doctor/dashboard', [App\Http\Controllers\DoctorController::class, 'dashboard']);
    Route::get('/doctor/patients', [App\Http\Controllers\DoctorController::class, 'patients']);
    Route::get('/doctor/schedule', [App\Http\Controllers\DoctorController::class, 'schedule']);
    Route::get('/doctor/availability', [App\Http\Controllers\DoctorController::class, 'getAvailability']);
    Route::post('/doctor/availability', [App\Http\Controllers\DoctorController::class, 'updateAvailability']);
    Route::get('/doctor/settings', [App\Http\Controllers\DoctorController::class, 'getProfile']);
    Route::put('/doctor/settings', [App\Http\Controllers\DoctorController::class, 'updateProfile']);
    Route::get('/doctor/earnings', [App\Http\Controllers\DoctorController::class, 'getEarnings']);
    Route::get('/doctor/earnings/export', [App\Http\Controllers\DoctorController::class, 'exportEarnings']);
    
    
    // Admin Routes
    Route::prefix('admin')->group(function () {
        Route::get('/doctors/pending', [App\Http\Controllers\AdminController::class, 'getPendingDoctors']);
        Route::get('/doctors/all', [App\Http\Controllers\AdminController::class, 'getAllDoctors']);
        Route::put('/doctors/{id}/approve', [App\Http\Controllers\AdminController::class, 'approveDoctor']);
        Route::put('/doctors/{id}/reject', [App\Http\Controllers\AdminController::class, 'rejectDoctor']);
        Route::put('/doctors/{id}/pricing', [App\Http\Controllers\AdminController::class, 'updateDoctorPricing']);
        Route::delete('/doctors/{id}', [App\Http\Controllers\AdminController::class, 'deleteDoctor']);
        Route::put('/doctors/{id}/suspend', [App\Http\Controllers\AdminController::class, 'suspendDoctor']);
        Route::put('/doctors/{id}/activate', [App\Http\Controllers\AdminController::class, 'activateDoctor']);
        Route::get('/patients', [App\Http\Controllers\AdminController::class, 'getPatients']);
        Route::delete('/patients/{id}', [App\Http\Controllers\AdminController::class, 'deletePatient']);
        Route::put('/patients/{id}/suspend', [App\Http\Controllers\AdminController::class, 'suspendPatient']);
        Route::put('/patients/{id}/activate', [App\Http\Controllers\AdminController::class, 'activatePatient']);

        // Blog Management
        Route::post('/posts', [App\Http\Controllers\PostController::class, 'store']);
        Route::put('/posts/{id}', [App\Http\Controllers\PostController::class, 'update']);
        Route::delete('/posts/{id}', [App\Http\Controllers\PostController::class, 'destroy']);

        // Medical Records
        Route::get('/medical-records', [App\Http\Controllers\AdminController::class, 'getMedicalRecords']);

        // Dashboard Stats
        Route::get('/stats', [App\Http\Controllers\AdminController::class, 'getDashboardStats']);

        // Fetch All Appointments
        Route::get('/appointments', [App\Http\Controllers\AdminController::class, 'getAppointments']);

        // Subscriber Management
        Route::get('/subscribers', [App\Http\Controllers\SubscriberController::class, 'index']);
        Route::delete('/subscribers/{id}', [App\Http\Controllers\SubscriberController::class, 'destroy']);
        Route::post('/subscribers/send-email', [App\Http\Controllers\SubscriberController::class, 'sendEmail']);


    });

    // Super Admin Routes
    Route::prefix('super-admin')->group(function () {
        Route::get('/users', [App\Http\Controllers\SuperAdminController::class, 'getAllUsers']);
        Route::post('/admins', [App\Http\Controllers\SuperAdminController::class, 'createAdminUser']);
        Route::post('/adjust-credits', [App\Http\Controllers\SuperAdminController::class, 'adjustCredits']);
        Route::post('/update-global-pool', [App\Http\Controllers\SuperAdminController::class, 'updateGlobalPool']);
        Route::get('/credit-transactions', [App\Http\Controllers\SuperAdminController::class, 'getCreditTransactions']);
        Route::delete('/users/{id}', [App\Http\Controllers\SuperAdminController::class, 'deleteUser']);
        Route::get('/system-stats', [App\Http\Controllers\SuperAdminController::class, 'getSystemStats']);
    });

    // Video Call Credit Routes
    Route::prefix('video-call')->group(function () {
        Route::post('/check-credits', [App\Http\Controllers\VideoCallController::class, 'checkCredits']);
        Route::post('/consume-credits', [App\Http\Controllers\VideoCallController::class, 'consumeCredits']);
    });
});
