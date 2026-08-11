<?php

namespace App\Http\Controllers;

use App\Models\HeldOrder;
use App\Models\HeldOrderItem;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Services\LowStockAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    public function index(): View
    {
        $sourceProducts = Product::query()
            ->with('activePromotion')
            ->where('is_active', true)
            ->where('current_stock', '>', 0)
            ->orderBy('category')
            ->orderBy('name')
            ->get();

        $categoryLabels = [];

        foreach ($sourceProducts as $product) {
            $label = $this->cleanCategory($product->category);
            $categoryLabels[$this->categoryKey($label)] ??= $label;
        }

        $products = $sourceProducts->map(function (Product $product) use ($categoryLabels) {
            $categoryKey = $this->categoryKey($this->cleanCategory($product->category));

            return [
                'id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => $product->selling_price,
                'basePrice' => (float) $product->unit_price,
                'promotion' => $product->activePromotion ? [
                    'label' => $product->activePromotion->action_label,
                    'discount' => (float) $product->activePromotion->discount_percent,
                    'bundleNote' => $product->activePromotion->bundle_note,
                ] : null,
                'category' => $categoryLabels[$categoryKey],
                'categoryKey' => $categoryKey,
                'stock' => $product->current_stock,
            ];
        });

        $categories = collect($categoryLabels)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();

        $checkoutLogs = SalesTransaction::query()
            ->with('staff')
            ->withCount('items')
            ->withSum('items as units_count', 'quantity')
            ->latest('sale_date')
            ->limit(25)
            ->get();

        $heldOrders = HeldOrder::query()
            ->with(['staff', 'items.product'])
            ->where('status', 'held')
            ->latest('held_at')
            ->get()
            ->map(fn (HeldOrder $heldOrder) => $this->heldOrderData($heldOrder));

        return view('staff.pos', compact('products', 'categories', 'checkoutLogs', 'heldOrders'));
    }

    private function cleanCategory(?string $category): string
    {
        $category = preg_replace('/\s+/u', ' ', trim((string) $category));

        return $category !== '' ? $category : 'Uncategorized';
    }

    private function categoryKey(string $category): string
    {
        return mb_strtolower($category, 'UTF-8');
    }

    public function showReceipt(SalesTransaction $sale): View
    {
        $sale->load(['items.product', 'staff']);

        return view('staff.pos-receipt', compact('sale'));
    }

    public function storeHold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $heldOrder = DB::transaction(function () use ($validated, $request) {
            $products = Product::query()
                ->with('activePromotion')
                ->whereIn('product_id', collect($validated['items'])->pluck('product_id'))
                ->get()
                ->keyBy('product_id');

            foreach ($validated['items'] as $item) {
                $product = $products->get($item['product_id']);

                if (! $product || $product->current_stock < $item['quantity']) {
                    throw ValidationException::withMessages([
                        'items' => ($product?->name ?? 'A selected product').' no longer has enough stock to hold this quantity.',
                    ]);
                }
            }

            $heldOrder = HeldOrder::create([
                'staff_id' => $request->user()->id,
                'status' => 'held',
                'held_at' => now(),
            ]);

            foreach ($validated['items'] as $item) {
                $product = $products->get($item['product_id']);
                HeldOrderItem::create([
                    'held_order_id' => $heldOrder->held_order_id,
                    'product_id' => $product->product_id,
                    'quantity' => $item['quantity'],
                    'unit_price' => $product->selling_price,
                ]);
            }

            return $heldOrder->load(['staff', 'items.product']);
        });

        return response()->json([
            'message' => 'Order '.$this->holdNumber($heldOrder).' is saved and ready to resume.',
            'hold' => $this->heldOrderData($heldOrder),
        ], 201);
    }

    public function cancelHold(HeldOrder $heldOrder): JsonResponse
    {
        if ($heldOrder->status !== 'held') {
            throw ValidationException::withMessages(['hold' => 'This held order is no longer active.']);
        }

        $heldOrder->update([
            'status' => 'cancelled',
            'resolved_at' => now(),
        ]);

        return response()->json(['message' => $this->holdNumber($heldOrder).' was cancelled.']);
    }

    public function store(Request $request, LowStockAlertService $alerts): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'held_order_id' => ['nullable', 'integer', 'exists:held_orders,held_order_id'],
        ]);

        $sale = DB::transaction(function () use ($validated, $request) {
            $subtotal = 0;
            $saleItems = [];
            $heldOrder = null;

            if (! empty($validated['held_order_id'])) {
                $heldOrder = HeldOrder::query()->lockForUpdate()->findOrFail($validated['held_order_id']);

                if ($heldOrder->status !== 'held') {
                    throw ValidationException::withMessages(['held_order_id' => 'This held order is no longer active.']);
                }
            }

            foreach ($validated['items'] as $cartItem) {
                $product = Product::query()->lockForUpdate()->findOrFail($cartItem['product_id']);
                $product->load('activePromotion');
                $quantity = (int) $cartItem['quantity'];

                if ($product->current_stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "{$product->name} only has {$product->current_stock} stock left.",
                    ]);
                }

                $sellingPrice = $product->selling_price;
                $lineTotal = $sellingPrice * $quantity;
                $subtotal += $lineTotal;

                $product->update(['current_stock' => $product->current_stock - $quantity]);

                $saleItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_sale_price' => $sellingPrice,
                    'unit_cost' => (float) $product->unit_cost,
                    'line_total' => $lineTotal,
                ];
            }

            $tax = round($subtotal * 0.12, 2);
            $total = round($subtotal + $tax, 2);

            $sale = SalesTransaction::create([
                'staff_id' => $request->user()->id,
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total_sale_amount' => $total,
                'payment_status' => 'paid',
                'payment_method' => $validated['payment_method'] ?? 'cash',
                'sale_date' => now(),
            ]);

            foreach ($saleItems as $item) {
                SalesItem::create([
                    'sale_id' => $sale->sale_id,
                    'product_id' => $item['product']->product_id,
                    'quantity' => $item['quantity'],
                    'unit_sale_price' => $item['unit_sale_price'],
                    'unit_cost' => $item['unit_cost'],
                    'line_total' => $item['line_total'],
                ]);

                InventoryLedger::create([
                    'product_id' => $item['product']->product_id,
                    'user_id' => $request->user()->id,
                    'qty_in' => 0,
                    'qty_out' => $item['quantity'],
                    'reason_code' => 'POS_SALE',
                    'logs' => "Sold through POS transaction #{$sale->sale_id}.",
                ]);
            }

            $heldOrder?->update([
                'status' => 'completed',
                'completed_sale_id' => $sale->sale_id,
                'resolved_at' => now(),
            ]);

            return $sale->load('items.product');
        });

        $receiptNumber = 'POS-'.str_pad((string) $sale->sale_id, 6, '0', STR_PAD_LEFT);

        $sale->items->each(fn (SalesItem $item) => $alerts->checkProduct($item->product));

        return response()->json([
            'message' => "Payment processed. Receipt #{$receiptNumber} saved.",
            'sale_id' => $sale->sale_id,
            'total' => (float) $sale->total_sale_amount,
            'receipt' => [
                'number' => $receiptNumber,
                'url' => route('staff.pos.receipts.show', $sale),
                'date' => $sale->sale_date->format('M d, Y h:i A'),
                'cashier' => $request->user()->name,
                'payment_method' => ucfirst($sale->payment_method),
                'subtotal' => (float) $sale->subtotal,
                'tax' => (float) $sale->tax_amount,
                'total' => (float) $sale->total_sale_amount,
                'items' => $sale->items->map(fn (SalesItem $item) => [
                    'name' => $item->product->name,
                    'sku' => $item->product->sku,
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_sale_price,
                    'line_total' => (float) $item->line_total,
                ])->values(),
            ],
        ], 201);
    }

    private function holdNumber(HeldOrder $heldOrder): string
    {
        return 'HOLD-'.str_pad((string) $heldOrder->held_order_id, 6, '0', STR_PAD_LEFT);
    }

    private function heldOrderData(HeldOrder $heldOrder): array
    {
        return [
            'id' => $heldOrder->held_order_id,
            'number' => $this->holdNumber($heldOrder),
            'date' => $heldOrder->held_at->format('M d, Y h:i A'),
            'cashier' => $heldOrder->staff?->name ?? 'Former staff',
            'total' => (float) $heldOrder->items->sum(fn (HeldOrderItem $item) => $item->quantity * (float) $item->unit_price),
            'cancel_url' => route('staff.pos.holds.cancel', $heldOrder),
            'items' => $heldOrder->items->map(fn (HeldOrderItem $item) => [
                'product_id' => $item->product_id,
                'name' => $item->product->name,
                'sku' => $item->product->sku,
                'quantity' => $item->quantity,
                'unit_price' => (float) $item->unit_price,
                'stock' => $item->product->current_stock,
            ])->values(),
        ];
    }
}
