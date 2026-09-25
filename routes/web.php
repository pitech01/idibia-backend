<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/fix-cache', [App\Http\Controllers\MaintenanceController::class, 'fixCache']);

Route::get('/link-storage', function () {
    try {
        \Illuminate\Support\Facades\Artisan::call('storage:link');
        return 'Storage link created successfully!';
    } catch (\Exception $e) {
        return 'Failed to link storage: ' . $e->getMessage();
    }
});

Route::get('/doctors/{id}/document/{type}', [App\Http\Controllers\AdminController::class, 'getDoctorDocument']);
Route::get('/admin/doctors/{id}/document/{type}', [App\Http\Controllers\AdminController::class, 'getDoctorDocument']);
Route::get('/doctor-documents/{path}', [App\Http\Controllers\AdminController::class, 'getDocumentByPath'])->where('path', '.*');
Route::get('/storage/{path}', [App\Http\Controllers\AdminController::class, 'getDocumentByPath'])->where('path', '.*');
