<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AccountManagementController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\LoginVerificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\CompatibilityController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeadStockController;
use App\Http\Controllers\LowStocksController;
use App\Http\Controllers\InventoryLiveController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductsController;
use App\Http\Controllers\ReturnsController;
use App\Http\Controllers\StockManagementController;
use App\Http\Controllers\SupplierPriceController;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::view('/', 'auth.role-access')->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
    Route::get('/login/verify', [LoginVerificationController::class, 'create'])->name('login.verify');
    Route::post('/login/verify', [LoginVerificationController::class, 'store'])->name('login.verify.store');
    Route::post('/login/verify/resend', [LoginVerificationController::class, 'resend'])
        ->middleware('throttle:3,10')
        ->name('login.verify.resend');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])
        ->middleware('throttle:5,1')
        ->name('password.update');
    Route::post('/register', [RegisteredUserController::class, 'store'])
        ->middleware('throttle:3,1')
        ->name('register.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/session/activity', fn () => response()->noContent())
        ->middleware(['verified', 'throttle:10,1'])->name('session.activity');
    Route::view('/verify-email', 'auth.verify-email')->name('verification.notice');

    Route::get('/verify-email/{id}/{hash}', function (EmailVerificationRequest $request) {
        $request->fulfill();

        return redirect()->route($request->user()->role.'.dashboard');
    })->middleware('signed')->name('verification.verify');

    Route::post('/email/verification-notification', function (Request $request) {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return back()->with('status', 'verification-link-sent');
    })->middleware('throttle:6,1')->name('verification.send');
    Route::get('/inventory/live', InventoryLiveController::class)->middleware(['verified', 'throttle:120,1'])->name('inventory.live');
});

Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/admin/accounts', [AccountManagementController::class, 'index'])->name('admin.accounts');
    Route::patch('/admin/accounts/{user}/approve', [AccountManagementController::class, 'approve'])->name('admin.accounts.approve');
    Route::patch('/admin/accounts/{user}/disable', [AccountManagementController::class, 'disable'])->name('admin.accounts.disable');
    Route::patch('/admin/accounts/{user}/reactivate', [AccountManagementController::class, 'reactivate'])->name('admin.accounts.reactivate');
    Route::get('/admin/dashboard', [DashboardController::class, 'admin'])->name('admin.dashboard');
    Route::get('/admin/inventory', [StockManagementController::class, 'index'])->name('admin.inventory');
    Route::post('/admin/inventory/products', [StockManagementController::class, 'storeProduct'])->name('admin.inventory.products.store');
    Route::patch('/admin/inventory/products/{product}', [StockManagementController::class, 'updateProduct'])->name('admin.inventory.products.update');
    Route::patch('/admin/inventory/products/{product}/shelf-location', [StockManagementController::class, 'updateShelfLocation'])->name('admin.inventory.products.shelf-location');
    Route::patch('/admin/inventory/products/{product}/category', [StockManagementController::class, 'updateCategory'])->name('admin.inventory.products.category');
    Route::delete('/admin/inventory/products/{product}', [StockManagementController::class, 'destroyProduct'])->middleware('throttle:5,1')->name('admin.inventory.products.destroy');
    Route::post('/admin/inventory/movements', [StockManagementController::class, 'storeMovement'])->name('admin.inventory.movements.store');
    Route::get('/admin/products', [ProductsController::class, 'index'])->name('admin.products');
    Route::get('/admin/pos', [PosController::class, 'index'])->name('admin.pos');
    Route::post('/admin/pos/checkout', [PosController::class, 'store'])->name('admin.pos.checkout');
    Route::post('/admin/pos/holds', [PosController::class, 'storeHold'])->name('admin.pos.holds.store');
    Route::delete('/admin/pos/holds/{heldOrder}', [PosController::class, 'cancelHold'])->name('admin.pos.holds.cancel');
    Route::get('/admin/pos/receipts/{sale}', [PosController::class, 'showReceipt'])->name('admin.pos.receipts.show');
    Route::get('/admin/pos', [PosController::class, 'index'])->name('admin.pos');
    Route::post('/admin/pos/checkout', [PosController::class, 'store'])->name('admin.pos.checkout');
    Route::post('/admin/pos/holds', [PosController::class, 'storeHold'])->name('admin.pos.holds.store');
    Route::delete('/admin/pos/holds/{heldOrder}', [PosController::class, 'cancelHold'])->name('admin.pos.holds.cancel');
    Route::get('/admin/pos/receipts/{sale}', [PosController::class, 'showReceipt'])->name('admin.pos.receipts.show');
    Route::get('/admin/analytics', [AnalyticsController::class, 'index'])->name('admin.analytics');
    Route::get('/admin/analytics/export/excel', [AnalyticsController::class, 'export'])->middleware('throttle:10,1')->name('admin.analytics.export');
    Route::get('/admin/low-stocks', [LowStocksController::class, 'index'])->name('admin.low-stocks');
    Route::post('/admin/low-stocks/settings', [LowStocksController::class, 'updateSettings'])->name('admin.low-stocks.settings');
    Route::post('/admin/low-stocks/run-now', [LowStocksController::class, 'runNow'])->name('admin.low-stocks.run-now');
    Route::get('/admin/deadstock', [DeadStockController::class, 'index'])->name('admin.dead-stock');
    Route::post('/admin/dead-stock/train-model', [DeadStockController::class, 'trainModel'])->middleware('throttle:2,10')->name('admin.dead-stock.train-model');
    Route::get('/admin/dead-stock', [DeadStockController::class, 'index']);
    Route::post('/admin/dead-stock/{product}/promotions', [DeadStockController::class, 'applyPromotion'])->name('admin.dead-stock.promotions.apply');
    Route::delete('/admin/dead-stock/{product}/promotions', [DeadStockController::class, 'endPromotion'])->name('admin.dead-stock.promotions.end');
    Route::patch('/admin/dead-stock/{product}/archive', [DeadStockController::class, 'archive'])->name('admin.dead-stock.archive');
    Route::patch('/admin/dead-stock/{product}/restore', [DeadStockController::class, 'restore'])->name('admin.dead-stock.restore');
    Route::get('/admin/returns', [ReturnsController::class, 'index'])->name('admin.returns');
    Route::post('/admin/returns/customer', [ReturnsController::class, 'storeReturn'])->name('admin.returns.customer.store');
    Route::patch('/admin/returns/customer/{customerReturn}/review', [ReturnsController::class, 'reviewReturn'])->name('admin.returns.customer.review');
    Route::post('/admin/returns/damage', [ReturnsController::class, 'storeDamage'])->name('admin.returns.damage.store');
    Route::patch('/admin/returns/damage/{damagedGood}/review', [ReturnsController::class, 'reviewDamage'])->name('admin.returns.damage.review');
    Route::get('/admin/suppliers', [SupplierPriceController::class, 'index'])->name('admin.suppliers');
    Route::post('/admin/suppliers/imports', [SupplierPriceController::class, 'upload'])->name('admin.suppliers.imports.upload');
    Route::post('/admin/suppliers/imports/{supplierImport}/approve', [SupplierPriceController::class, 'approve'])->name('admin.suppliers.imports.approve');
    Route::post('/admin/suppliers/imports/{supplierImport}/reject', [SupplierPriceController::class, 'reject'])->name('admin.suppliers.imports.reject');
    Route::patch('/admin/suppliers/prices/{supplierPrice}/match', [SupplierPriceController::class, 'matchProduct'])->name('admin.suppliers.prices.match');
    Route::delete('/admin/suppliers/prices/{supplierPrice}/match', [SupplierPriceController::class, 'unmatchProduct'])->name('admin.suppliers.prices.unmatch');
    Route::patch('/admin/suppliers/prices/{supplierPrice}/apply-cost', [SupplierPriceController::class, 'applyCost'])->name('admin.suppliers.prices.apply-cost');
    Route::post('/admin/suppliers/prices/{supplierPrice}/create-product', [SupplierPriceController::class, 'createProduct'])->name('admin.suppliers.prices.create-product');
    Route::post('/admin/suppliers/prices/bulk-create-products', [SupplierPriceController::class, 'bulkCreateProducts'])->name('admin.suppliers.prices.bulk-create-products');
    Route::delete('/admin/suppliers/data', [SupplierPriceController::class, 'purge'])->middleware('throttle:5,1')->name('admin.suppliers.purge');
    Route::get('/admin/compatibility', [CompatibilityController::class, 'index'])->name('admin.compatibility');
    Route::post('/admin/compatibility/ai-recommendations', [CompatibilityController::class, 'index'])->middleware('throttle:3,1')->name('admin.compatibility.ai');
});

Route::middleware(['auth', 'verified', 'role:staff'])->group(function () {
    Route::get('/staff/dashboard', [DashboardController::class, 'staff'])->name('staff.dashboard');
    Route::get('/staff/products', [ProductsController::class, 'index'])->name('staff.products');
    Route::get('/staff/pos', [PosController::class, 'index'])->name('staff.pos');
    Route::post('/staff/pos/checkout', [PosController::class, 'store'])->name('staff.pos.checkout');
    Route::post('/staff/pos/holds', [PosController::class, 'storeHold'])->name('staff.pos.holds.store');
    Route::delete('/staff/pos/holds/{heldOrder}', [PosController::class, 'cancelHold'])->name('staff.pos.holds.cancel');
    Route::get('/staff/pos/receipts/{sale}', [PosController::class, 'showReceipt'])->name('staff.pos.receipts.show');
    Route::get('/staff/returns', [ReturnsController::class, 'index'])->name('staff.returns');
    Route::post('/staff/returns/customer', [ReturnsController::class, 'storeReturn'])->name('staff.returns.customer.store');
    Route::post('/staff/returns/damage', [ReturnsController::class, 'storeDamage'])->name('staff.returns.damage.store');
    Route::get('/staff/compatibility', [CompatibilityController::class, 'index'])->name('staff.compatibility');
    Route::post('/staff/compatibility/ai-recommendations', [CompatibilityController::class, 'index'])->middleware('throttle:3,1')->name('staff.compatibility.ai');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth')
    ->name('logout');
