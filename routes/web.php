<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\PlanChangeController;
use Illuminate\Support\Facades\Route;

Route::get('/login', [AuthController::class, 'show'])->name('login')->middleware('guest');
Route::post('/login', [AuthController::class, 'login'])->name('login.attempt')->middleware('guest');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout')->middleware('auth');

Route::middleware('auth')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::get('/customers/{customer}', [CustomerController::class, 'show'])->name('customers.show');

    Route::get('/customers/{customer}/change-plan', [PlanChangeController::class, 'show'])->name('plan-change.show');
    Route::post('/customers/{customer}/change-plan/preview', [PlanChangeController::class, 'preview'])->name('plan-change.preview');
    Route::post('/customers/{customer}/change-plan', [PlanChangeController::class, 'apply'])->name('plan-change.apply');

    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
});
