<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\StockManagementController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/', 'auth.role-access')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::post('/register', [RegisteredUserController::class, 'store'])->name('register.store');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::view('/admin/dashboard', 'admin.dashboard')->name('admin.dashboard');
    Route::get('/admin/inventory', [StockManagementController::class, 'index'])->name('admin.inventory');
    Route::post('/admin/inventory/products', [StockManagementController::class, 'storeProduct'])->name('admin.inventory.products.store');
    Route::post('/admin/inventory/movements', [StockManagementController::class, 'storeMovement'])->name('admin.inventory.movements.store');
    Route::view('/admin/products', 'admin.products')->name('admin.products');
    Route::view('/admin/analytics', 'admin.analytics')->name('admin.analytics');
    Route::view('/admin/low-stocks', 'admin.low-stocks')->name('admin.low-stocks');
    Route::view('/admin/deadstock', 'admin.dead-stock')->name('admin.dead-stock');
    Route::view('/admin/dead-stock', 'admin.dead-stock');
    Route::view('/admin/returns', 'admin.returns')->name('admin.returns');
    Route::view('/admin/suppliers', 'admin.suppliers')->name('admin.suppliers');
    Route::view('/admin/compatibility', 'admin.compatibility')->name('admin.compatibility');
});

Route::middleware(['auth', 'role:staff'])->group(function () {
    Route::view('/staff/dashboard', 'staff.dashboard')->name('staff.dashboard');
    Route::get('/staff/stock-management', [StockManagementController::class, 'index'])->name('staff.stock-management');
    Route::post('/staff/stock-management/products', [StockManagementController::class, 'storeProduct'])->name('staff.inventory.products.store');
    Route::post('/staff/stock-management/movements', [StockManagementController::class, 'storeMovement'])->name('staff.inventory.movements.store');
    Route::view('/staff/products', 'staff.products')->name('staff.products');
    Route::view('/staff/pos', 'staff.pos')->name('staff.pos');
    Route::view('/staff/returns', 'staff.returns')->name('staff.returns');
    Route::view('/staff/compatibility', 'staff.compatibility')->name('staff.compatibility');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
