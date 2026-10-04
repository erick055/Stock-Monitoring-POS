<?php

namespace App\Http\Controllers;

use App\Models\CustomerReturn;
use App\Models\DamagedGood;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Services\LowStockAlertService;
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
        $sales = SalesTransaction::query()
            ->with(['items.product', 'staff'])
            ->where('payment_status', 'paid')
            ->whereHas('items')
            ->latest('sale_date')
            ->limit(250)
            ->get();

        $saleIds = $sales->pluck('sale_id');
        $returnedQuantities = CustomerReturn::query()
            ->whereIn('sale_id', $saleIds)
            ->where('status', '!=', 'rejected')
            ->selectRaw('sale_id, product_id, SUM(quantity) as quantity')
            ->groupBy('sale_id', 'product_id')
            ->get()
            ->keyBy(fn (CustomerReturn $return) => "{$return->sale_id}:{$return->product_id}");
        $damagedQuantities = DamagedGood::query()
            ->where('status', '!=', 'rejected')
            ->whereIn('sale_id', $saleIds)
            ->selectRaw('sale_id, product_id, SUM(quantity) as quantity')
            ->groupBy('sale_id', 'product_id')
            ->get()
            ->keyBy(fn (DamagedGood $damage) => "{$damage->sale_id}:{$damage->product_id}");

        $receipts = $sales->map(function (SalesTransaction $sale) use ($returnedQuantities, $damagedQuantities) {
            $items = $sale->items
                ->groupBy('product_id')
                ->map(function ($lines, $productId) use ($sale, $returnedQuantities, $damagedQuantities) {
                    $sold = (int) $lines->sum('quantity');
                    $used = (int) ($returnedQuantities->get("{$sale->sale_id}:{$productId}")?->quantity ?? 0)
                        + (int) ($damagedQuantities->get("{$sale->sale_id}:{$productId}")?->quantity ?? 0);
                    $total = (float) $lines->sum('line_total');
                    $product = $lines->first()->product;

                    return [
                        'product_id' => (int) $productId,
                        'sku' => $product?->sku ?? 'Deleted SKU',
                        'name' => $product?->name ?? 'Deleted product',
                        'quantity_sold' => $sold,
                        'available_quantity' => max($sold - $used, 0),
                        'unit_price' => $sold > 0 ? round($total / $sold, 2) : 0,
                        'line_value' => round($total, 2),
                        'available_value' => round(($sold > 0 ? $total / $sold : 0) * max($sold - $used, 0), 2),
                    ];
                })
                ->filter(fn (array $item) => $item['available_quantity'] > 0)
                ->values();

            $number = $this->receiptNumber($sale->sale_id);
            $date = $sale->sale_date->format('M d, Y h:i A');

            return [
                'id' => $sale->sale_id,
                'number' => $number,
                'label' => "{$number} — {$date} — ₱".number_format((float) $sale->total_sale_amount, 2),
                'date' => $date,
                'cashier' => $sale->staff?->name ?? 'Former staff',
                'total' => number_format((float) $sale->total_sale_amount, 2),
                'items' => $items,
            ];
        })->filter(fn (array $receipt) => $receipt['items']->isNotEmpty())->values();
        $customerReturns = CustomerReturn::query()
            ->with(['product', 'sale', 'user'])
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('returned_at')
            ->limit(20)
            ->get();
        $damageLogs = DamagedGood::query()
            ->whereNotIn('status', ['pending', 'rejected'])
            ->with(['product', 'sale', 'user'])
            ->latest('reported_at')
            ->limit(20)
            ->get();

        $pendingDamages = $request->user()->role === 'admin'
            ? DamagedGood::with(['product', 'sale', 'user'])->where('status', 'pending')->oldest('reported_at')->limit(50)->get()
            : collect();
        $monthStart = now()->startOfMonth();
        $monthlyReturns = CustomerReturn::query()->where('returned_at', '>=', $monthStart)->count();
        $monthlySales = max(SalesTransaction::query()->where('sale_date', '>=', $monthStart)->count(), 1);

        $summary = [
            ['TOTAL RETURN THIS MONTH', number_format($monthlyReturns), 'Processed customer returns', 'purple'],
            ['DAMAGE ITEMS', number_format(DamagedGood::query()->whereNotIn('status', ['pending', 'rejected'])->sum('quantity')), 'Owner-accepted damage items', 'violet'],
            ['RETURN RATES', number_format(($monthlyReturns / $monthlySales) * 100, 1).'%', 'Returns vs monthly POS transactions', 'orange'],
        ];

        $viewRole = $request->user()->role;

        return view('returns.index', compact('receipts', 'customerReturns', 'damageLogs', 'pendingDamages', 'summary', 'viewRole'));
    }

    public function storeReturn(Request $request, LowStockAlertService $alerts): RedirectResponse
    {
        // Never trust a staff-supplied approval/rejection status.
        if ($request->user()->role !== 'admin') {
            $request->merge(['status' => 'pending']);
        }
        $validated = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales_transactions,sale_id'],
            'product_id' => ['required', 'integer', 'exists:products,product_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:255'],
            'item_condition' => ['required', Rule::in(['sellable', 'damaged'])],
            'refund_amount' => ['nullable', 'numeric', 'min:0'],
            'status' => ['required', Rule::in(['approved', 'pending', 'rejected'])],
        ]);

        $product = DB::transaction(function () use ($validated, $request) {
            [$sale, $soldQuantity, $availableQuantity, $unitPrice] = $this->lockedReceiptItem(
                (int) $validated['sale_id'],
                (int) $validated['product_id']
            );
            $quantity = (int) $validated['quantity'];
            if ($quantity > $availableQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$availableQuantity} of this product remain returnable on {$this->receiptNumber($sale->sale_id)} (sold: {$soldQuantity}).",
                ]);
            }

            $refund = (float) ($validated['refund_amount'] ?? 0);
            $maximumRefund = round($unitPrice * $quantity, 2);
            if ($refund > $maximumRefund + 0.001) {
                throw ValidationException::withMessages([
                    'refund_amount' => 'Refund cannot exceed ₱'.number_format($maximumRefund, 2).' for the selected receipt item and quantity.',
                ]);
            }

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
                    'logs' => "Customer return #{$return->return_id} from {$this->receiptNumber($sale->sale_id)} approved and added back to sellable stock.",
                ]);
            }

            return $product->fresh();
        });

        $alerts->checkProduct($product);

        return back()->with('success', $validated['status'] === 'pending'
            ? 'Return submitted for owner review. No refund has been approved and stock is unchanged.'
            : 'Customer return recorded successfully.');
    }

    public function reviewReturn(Request $request, CustomerReturn $customerReturn, LowStockAlertService $alerts): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $validated = $request->validate(['decision' => ['required', Rule::in(['approved', 'rejected'])]]);
        $product = DB::transaction(function () use ($customerReturn, $validated, $request) {
            // Match receipt-first lock order used by return/damage submissions.
            $sale = SalesTransaction::where('payment_status', 'paid')->lockForUpdate()->findOrFail($customerReturn->sale_id);
            $return = CustomerReturn::lockForUpdate()->findOrFail($customerReturn->return_id);
            if ($return->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This return has already been reviewed.']);
            }
            $product = Product::lockForUpdate()->findOrFail($return->product_id);
            $return->update(['status' => $validated['decision']]);
            if ($validated['decision'] === 'approved' && $return->item_condition === 'sellable') {
                $product->increment('current_stock', $return->quantity);
                InventoryLedger::create([
                    'product_id' => $product->product_id, 'user_id' => $request->user()->id,
                    'qty_in' => $return->quantity, 'qty_out' => 0, 'reason_code' => 'CUSTOMER_RETURN',
                    'logs' => "Owner approved customer return #{$return->return_id} from {$this->receiptNumber($sale->sale_id)} and added sellable items back to stock.",
                ]);
            }
            return $product->fresh();
        });
        if ($validated['decision'] === 'approved') $alerts->checkProduct($product);

        return back()->with('success', $validated['decision'] === 'approved'
            ? 'Return and requested refund approved. Only sellable items were added back to stock.'
            : 'Return rejected. Stock is unchanged and the receipt quantity is available again.');
    }

    public function storeDamage(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales_transactions,sale_id'],
            'product_id' => ['required', 'integer', 'exists:products,product_id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'damage_reason' => ['required', 'string', 'max:255'],
            'replacement_status' => ['required', Rule::in(['pending', 'ordered', 'replaced', 'not_replaceable'])],
            'status' => ['required', Rule::in(['reported', 'reviewed', 'disposed'])],
        ]);

        DB::transaction(function () use ($validated, $request) {
            [$sale, $soldQuantity, $availableQuantity] = $this->lockedReceiptItem(
                (int) $validated['sale_id'],
                (int) $validated['product_id']
            );
            if ((int) $validated['quantity'] > $availableQuantity) {
                throw ValidationException::withMessages([
                    'quantity' => "Only {$availableQuantity} of this product remain reportable on {$this->receiptNumber($sale->sale_id)} (sold: {$soldQuantity}).",
                ]);
            }

            DamagedGood::create([
                ...$validated,
                'status' => $request->user()->role === 'admin' ? $validated['status'] : 'pending',
                'user_id' => $request->user()->id,
                'reported_at' => now(),
            ]);
        });

        return back()->with('success', $request->user()->role === 'admin'
            ? 'Damage recorded. Inventory was not deducted again.'
            : 'Damage submitted for owner review. It will appear in the Damage Log only after acceptance.');
    }

    public function reviewDamage(Request $request, DamagedGood $damagedGood): RedirectResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $validated = $request->validate(['decision' => ['required', Rule::in(['accepted', 'rejected'])]]);
        DB::transaction(function () use ($damagedGood, $validated) {
            SalesTransaction::lockForUpdate()->findOrFail($damagedGood->sale_id);
            $damage = DamagedGood::lockForUpdate()->findOrFail($damagedGood->damage_id);
            if ($damage->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This damage report has already been reviewed.']);
            }
            $damage->update(['status' => $validated['decision'] === 'accepted' ? 'reviewed' : 'rejected']);
        });
        return back()->with('success', $validated['decision'] === 'accepted'
            ? 'Damage accepted and displayed in the Damage Log. Stock is unchanged.'
            : 'Damage rejected. It will not appear in the Damage Log; receipt quantity is available again.');
    }

    private function lockedReceiptItem(int $saleId, int $productId): array
    {
        $sale = SalesTransaction::query()
            ->where('payment_status', 'paid')
            ->lockForUpdate()
            ->find($saleId);

        if (! $sale) {
            throw ValidationException::withMessages(['sale_id' => 'Please select a completed customer receipt.']);
        }

        $lines = SalesItem::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->lockForUpdate()
            ->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['product_id' => 'The selected product is not included in this receipt.']);
        }

        $soldQuantity = (int) $lines->sum('quantity');
        $returned = (int) CustomerReturn::query()
            ->where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->where('status', '!=', 'rejected')
            ->sum('quantity');
        $damaged = (int) DamagedGood::query()
            ->where('status', '!=', 'rejected')
            ->where('sale_id', $saleId)
            ->where('product_id', $productId)
            ->sum('quantity');
        $unitPrice = $soldQuantity > 0 ? (float) $lines->sum('line_total') / $soldQuantity : 0;

        return [$sale, $soldQuantity, max($soldQuantity - $returned - $damaged, 0), $unitPrice];
    }

    private function receiptNumber(int $saleId): string
    {
        return 'POS-'.str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
    }
}
