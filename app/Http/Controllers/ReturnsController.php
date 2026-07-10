<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturn;
use App\Models\DamagedGood;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesTransaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ReturnsController extends Controller
{
    public function index(Request $request): View
    {
        $products = Product::query()->where('is_active', true)->orderBy('name')->get();
        $customerReturns = CustomerReturn::query()
            ->with(['product', 'sale', 'user'])
            ->latest('returned_at')
            ->limit(20)
            ->get();
        $damageLogs = DamagedGood::query()
            ->with(['product', 'user'])
            ->latest('reported_at')
            ->limit(20)
            ->get();

        $monthStart = now()->startOfMonth();
        $monthlyReturns = CustomerReturn::query()->where('returned_at', '>=', $monthStart)->count();
        $monthlySales = max(SalesTransaction::query()->where('sale_date', '>=', $monthStart)->count(), 1);

        $summary = [
            ['TOTAL RETURN THIS MONTH', number_format($monthlyReturns), 'Processed customer returns', 'purple'],
            ['DAMAGE ITEMS', number_format(DamagedGood::query()->sum('quantity')), 'Items flagged as damaged', 'violet'],
            ['RETURN RATES', number_format(($monthlyReturns / $monthlySales) * 100, 1) . '%', 'Returns vs monthly POS transactions', 'orange'],
        ];

        $viewRole = $request->user()->role;

        return view('returns.index', compact('products', 'customerReturns', 'damageLogs', 'summary', 'viewRole'));
    }

    public function storeReturn(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'sale_id' => ['nullable', 'integer', 'exists:sales_transactions,sale_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'item_condition' => ['required', Rule::in(['sellable', 'damaged'])],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['approved', 'pending', 'rejected'])],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);

            $return = CustomerReturn::create([
                ...$validated,
                'user_id' => $request->user()->id,
                'refund_amount' => $validated['refund_amount'] ?? 0,
                'returned_at' => now(),
            ]);

            if ($validated['status'] === 'approved' && $validated['item_condition'] === 'sellable') {
                $product->update(['current_stock' => $product->current_stock + (int) $validated['quantity']]);

                InventoryLedger::create([
                    'product_id' => $product->product_id,
                    'user_id' => $request->user()->id,
                    'qty_in' => $validated['quantity'],
                    'qty_out' => 0,
                    'reason_code' => 'CUSTOMER_RETURN',
                    'logs' => "Customer return #{$return->return_id} approved and added back to sellable stock.",
                ]);
            }
        });

        return back()->with('success', 'Customer return recorded successfully.');
    }

    public function storeDamage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'quantity' => ['required', 'integer', 'min:1'],
            'damage_reason' => ['required', 'string', 'max:255'],
            'replacement_status' => ['required', Rule::in(['pending', 'ordered', 'replaced', 'not_replaceable'])],
            'status' => ['required', Rule::in(['reported', 'reviewed', 'disposed'])],
        ]);

        DB::transaction(function () use ($validated, $request) {
            $product = Product::query()->lockForUpdate()->findOrFail($validated['product_id']);

            if ($product->current_stock < (int) $validated['quantity']) {
                throw ValidationException::withMessages([
                    'quantity' => "{$product->name} only has {$product->current_stock} stock available.",
                ]);
            }

            $damage = DamagedGood::create([
                ...$validated,
                'user_id' => $request->user()->id,
                'reported_at' => now(),
            ]);

            $product->update(['current_stock' => $product->current_stock - (int) $validated['quantity']]);

            InventoryLedger::create([
                'product_id' => $product->product_id,
                'user_id' => $request->user()->id,
                'qty_in' => 0,
                'qty_out' => $validated['quantity'],
                'reason_code' => 'DAMAGED_GOODS',
                'logs' => "Damage log #{$damage->damage_id} removed from sellable inventory.",
            ]);
        });

        return back()->with('success', 'Damaged goods recorded successfully.');
    }
}
