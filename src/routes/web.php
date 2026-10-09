<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerMergeController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InquiryController;
use App\Http\Controllers\PasswordResetController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login']);

    Route::get('/forgot-password', [PasswordResetController::class, 'requestLink'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])->name('password.email');
    Route::get('/reset-password/{token}', [PasswordResetController::class, 'resetForm'])->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])->name('password.update');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');
    Route::post('/customers/{winner}/merge', [CustomerMergeController::class, 'store'])->name('customers.merge');

    Route::get('/inquiries', [InquiryController::class, 'index'])->name('inquiries.index');
    Route::get('/inquiries/create', [InquiryController::class, 'create'])->name('inquiries.create');
    Route::post('/inquiries', [InquiryController::class, 'store'])->name('inquiries.store');
    Route::get('/inquiries/{inquiry}', [InquiryController::class, 'show'])->name('inquiries.show');
    Route::post('/inquiries/{inquiry}/status', [InquiryController::class, 'changeStatus'])->name('inquiries.status');
});
