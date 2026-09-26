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
            ->latest('returned_at')
            ->limit(20)
            ->get();
        $damageLogs = DamagedGood::query()
            ->with(['product', 'sale', 'user'])
            ->latest('reported_at')
            ->limit(20)
            ->get();

        $monthStart = now()->startOfMonth();
        $monthlyReturns = CustomerReturn::query()->where('returned_at', '>=', $monthStart)->count();
        $monthlySales = max(SalesTransaction::query()->where('sale_date', '>=', $monthStart)->count(), 1);

        $summary = [
            ['TOTAL RETURN THIS MONTH', number_format($monthlyReturns), 'Processed customer returns', 'purple'],
            ['DAMAGE ITEMS', number_format(DamagedGood::query()->sum('quantity')), 'Items flagged as damaged', 'violet'],
            ['RETURN RATES', number_format(($monthlyReturns / $monthlySales) * 100, 1).'%', 'Returns vs monthly POS transactions', 'orange'],
        ];

        $viewRole = $request->user()->role;

        return view('returns.index', compact('receipts', 'customerReturns', 'damageLogs', 'summary', 'viewRole'));
    }

    public function storeReturn(Request $request, LowStockAlertService $alerts): RedirectResponse
    {
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

        return back()->with('success', 'Customer return recorded successfully.');
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
                'user_id' => $request->user()->id,
                'reported_at' => now(),
            ]);
        });

        return back()->with('success', 'Receipt damage recorded successfully. Inventory was not deducted again because this item was already sold.');
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
