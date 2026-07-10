<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\DeadStockController;
use App\Http\Controllers\LowStocksController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ReturnsController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\ProductsController;
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
    Route::get('/admin/products', [ProductsController::class, 'index'])->name('admin.products');
    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');
    Route::get('/admin/low-stocks', [LowStocksController::class, 'index'])->name('admin.low-stocks');
    Route::get('/admin/deadstock', [DeadStockController::class, 'index'])->name('admin.dead-stock');
    Route::get('/admin/dead-stock', [DeadStockController::class, 'index']);
    Route::get('/admin/returns', [ReturnsController::class, 'index'])->name('admin.returns');
    Route::post('/admin/returns/customer', [ReturnsController::class, 'storeReturn'])->name('admin.returns.customer.store');
    Route::post('/admin/returns/damage', [ReturnsController::class, 'storeDamage'])->name('admin.returns.damage.store');
    Route::view('/admin/suppliers', 'admin.suppliers')->name('admin.suppliers');
    Route::view('/admin/compatibility', 'admin.compatibility')->name('admin.compatibility');
});

Route::middleware(['auth', 'role:staff'])->group(function () {
    Route::view('/staff/dashboard', 'staff.dashboard')->name('staff.dashboard');
    Route::get('/staff/stock-management', [StockManagementController::class, 'index'])->name('staff.stock-management');
    Route::post('/staff/stock-management/products', [StockManagementController::class, 'storeProduct'])->name('staff.inventory.products.store');
    Route::post('/staff/stock-management/movements', [StockManagementController::class, 'storeMovement'])->name('staff.inventory.movements.store');
    Route::get('/staff/products', [ProductsController::class, 'index'])->name('staff.products');
    Route::get('/staff/pos', [PosController::class, 'index'])->name('staff.pos');
    Route::post('/staff/pos/checkout', [PosController::class, 'store'])->name('staff.pos.checkout');
    Route::get('/staff/returns', [ReturnsController::class, 'index'])->name('staff.returns');
    Route::post('/staff/returns/customer', [ReturnsController::class, 'storeReturn'])->name('staff.returns.customer.store');
    Route::post('/staff/returns/damage', [ReturnsController::class, 'storeDamage'])->name('staff.returns.damage.store');
    Route::view('/staff/compatibility', 'staff.compatibility')->name('staff.compatibility');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
