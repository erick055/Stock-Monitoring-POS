<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class StockManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status', 'all');

        $products = Product::query()
            ->where('is_active', true)
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            }))
            ->orderBy('name')
            ->get()
            ->when($status !== 'all', fn ($items) => $items->filter(fn ($product) => $product->stock_status === $status)->values());

        $allProducts = Product::query()->where('is_active', true)->orderBy('name')->get();
        $ledgers = InventoryLedger::query()
            ->with(['product', 'user'])
            ->when($search, fn ($query) => $query->whereHas('product', function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%");
            }))
            ->latest('ledger_id')
            ->limit(50)
            ->get();

        $summary = [
            'total_sku' => $allProducts->count(),
            'total_units' => $allProducts->sum('current_stock'),
            'critical_low' => $allProducts->filter(fn ($product) => $product->stock_status === 'critical')->count(),
            'stock_value' => $allProducts->sum(fn ($product) => $product->current_stock * (float) $product->unit_cost),
        ];

        $view = $request->user()->role === 'admin' ? 'admin.stock-management' : 'staff.stock-management';

        return view($view, compact('products', 'allProducts', 'ledgers', 'summary', 'search', 'status'));
    }

    public function storeProduct(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sku' => ['required', 'string', 'max:100', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:100'],
            'unit_cost' => ['required', 'numeric', 'min:0'],
            'unit_price' => ['required', 'numeric', 'min:0'],
            'reorder_level' => ['required', 'integer', 'min:0'],
            'qty_in' => ['required', 'integer', 'min:0'],
            'reason_code' => ['required', 'string', 'max:50'],
            'logs' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::create([
                ...collect($validated)->only(['sku', 'name', 'category', 'unit_cost', 'unit_price', 'reorder_level'])->all(),
                'current_stock' => $validated['qty_in'],
            ]);

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => $validated['qty_in'],
                'qty_out' => 0,
                'reason_code' => $validated['reason_code'],
                'logs' => $validated['logs'] ?: 'Product added to inventory.',
            ]);
        });

        return back()->with('success', 'Product and opening inventory were added successfully.');
    }

    public function storeMovement(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'movement_type' => ['required', Rule::in(['in', 'out', 'adjustment'])],
            'quantity' => ['required', 'integer', 'not_in:0'],
            'reason_code' => ['required', 'string', 'max:50'],
            'logs' => ['nullable', 'string', 'max:1000'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);
            $quantity = (int) $validated['quantity'];

            if ($validated['movement_type'] === 'in' && $quantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock-in quantity must be positive.']);
            }

            if ($validated['movement_type'] === 'out' && $quantity < 0) {
                throw ValidationException::withMessages(['quantity' => 'Stock-out quantity must be positive.']);
            }

            $change = match ($validated['movement_type']) {
                'in' => $quantity,
                'out' => -$quantity,
                default => $quantity,
            };

            if ($product->current_stock + $change < 0) {
                throw ValidationException::withMessages(['quantity' => 'This movement would make the stock negative.']);
            }

            $product->update(['current_stock' => $product->current_stock + $change]);

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => max($change, 0),
                'qty_out' => max(-$change, 0),
                'reason_code' => $validated['reason_code'],
                'logs' => $validated['logs'],
            ]);
        });

        return back()->with('success', 'Stock movement recorded successfully.');
    }
}
