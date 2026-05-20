<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
Route::post('/two-factor-challenge', [AuthController::class, 'twoFactorChallenge']);
Route::post('/confirm-two-factor-and-login', [AuthController::class, 'confirm2FAAndLogin']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/user/two-factor-authentication', [AuthController::class, 'enableTwoFactor']);
    Route::get('/user/two-factor-qr-code', [AuthController::class, 'getTwoFactorQrCode']);
    Route::get('/user/two-factor-secret-key', [AuthController::class, 'getTwoFactorSecretKey']);

    Route::get('/admin/roles', [AdminController::class, 'getRoles']);
    Route::get('/admin/users', [AdminController::class, 'getUsers']);
    Route::post('/admin/users', [AdminController::class, 'createUser']);
    Route::put('/admin/users/{email}/role', [AdminController::class, 'updateRole']);

    Route::get('/user', function (Request $request) {
        return $request->user();
    });
});
