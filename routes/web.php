<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/', 'auth.role-access')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::view('/admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::view('/admin/inventory', 'admin.stock-management')->name('admin.inventory');
    Route::view('/admin/products', 'admin.products')->name('admin.products');
    Route::view('/admin/analytics', 'admin.analytics')->name('admin.analytics');
    Route::view('/admin/low-stocks', 'admin.low-stocks')->name('admin.low-stocks');
    Route::view('/admin/deadstock', 'admin.dead-stock')->name('admin.dead-stock');
    Route::view('/admin/dead-stock', 'admin.dead-stock');
    Route::view('/admin/suppliers', 'admin.suppliers')->name('admin.suppliers');
});

Route::middleware(['auth', 'role:staff'])->group(function () {
    Route::view('/staff/dashboard', 'staff.dashboard')->name('staff.dashboard');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
