<?php

namespace App\Http\Controllers;

use App\Models\HeldOrder;
use App\Models\HeldOrderItem;
use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Services\LowStockAlertService;
use App\Services\InventoryLiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PosController extends Controller
{
    private function authorizeCashierRecord(Request $request, int $staffId): void
    {
        abort_unless($request->user()->role === 'admin' || (int) $request->user()->id === $staffId,
            403, 'You do not have permission to access another cashier\'s record.');
    }

    public function index(Request $request, InventoryLiveService $liveInventory): View
    {
        $inventoryProducts = $liveInventory->products();
        $sourceProducts = $inventoryProducts->where('current_stock', '>', 0)->values();

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
                    'bundleProduct' => $product->activePromotion->bundleProduct ? [
                        'id' => $product->activePromotion->bundleProduct->product_id,
                        'name' => $product->activePromotion->bundleProduct->name,
                        'sku' => $product->activePromotion->bundleProduct->sku,
                    ] : null,
                ] : null,
                'category' => $categoryLabels[$categoryKey],
                'categoryKey' => $categoryKey,
                'shelfLocation' => $product->shelf_location,
                'stock' => $product->current_stock,
            ];
        });

        $categories = collect($categoryLabels)
            ->map(fn (string $label, string $key) => ['key' => $key, 'label' => $label])
            ->values();
        $inventoryVersion = $liveInventory->version($inventoryProducts);

        $checkoutLogs = SalesTransaction::query()
            ->when($request->user()->role !== 'admin', fn ($query) => $query->where('staff_id', $request->user()->id))
            ->with('staff')
            ->withCount('items')
            ->withSum('items as units_count', 'quantity')
            ->latest('sale_date')
            ->limit(25)
            ->get();

        $heldOrders = HeldOrder::query()
            ->when($request->user()->role !== 'admin', fn ($query) => $query->where('staff_id', $request->user()->id))
            ->with(['staff', 'items.product'])
            ->where('status', 'held')
            ->latest('held_at')
            ->get()
            ->map(fn (HeldOrder $heldOrder) => $this->heldOrderData($heldOrder));

        return view('staff.pos', compact('products', 'categories', 'checkoutLogs', 'heldOrders', 'inventoryVersion'));
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

    public function showReceipt(Request $request, SalesTransaction $sale): View
    {
        $this->authorizeCashierRecord($request, $sale->staff_id);
        $sale->load(['items.product', 'staff']);

        return view('staff.pos-receipt', compact('sale'));
    }

    public function storeHold(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'labor_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $heldOrder = DB::transaction(function () use ($validated, $request) {
            $products = Product::query()
                ->with('activePromotion')
                ->whereIn('product_id', collect($validated['items'])->pluck('product_id'))
                ->get()
                ->keyBy('product_id');

            $this->ensureBundleComposition($products, $validated['items']);

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
                'labor_amount' => round((float) ($validated['labor_amount'] ?? 0), 2),
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

    public function cancelHold(Request $request, HeldOrder $heldOrder): JsonResponse
    {
        DB::transaction(function () use ($request, $heldOrder): void {
            $heldOrder = HeldOrder::query()->lockForUpdate()->findOrFail($heldOrder->held_order_id);
            $this->authorizeCashierRecord($request, $heldOrder->staff_id);
            if ($heldOrder->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'This held order is no longer active.']);
            }
            $heldOrder->update(['status' => 'cancelled', 'resolved_at' => now()]);
        });

        return response()->json(['message' => $this->holdNumber($heldOrder).' was cancelled.']);
    }

    public function store(Request $request, LowStockAlertService $alerts): JsonResponse
    {
        $request->merge(['checkout_key' => $request->header('Idempotency-Key')]);
        $validated = $request->validate([
            'checkout_key' => ['required', 'uuid'],
            'items' => ['nullable', 'array'],
            'items.*.product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
            'held_order_id' => ['nullable', 'integer', 'exists:held_orders,held_order_id'],
            'labor_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
        ]);

        $cartItems = $validated['items'] ?? [];
        $laborAmount = round((float) ($validated['labor_amount'] ?? 0), 2);
        if ($cartItems === [] && ! empty($validated['held_order_id'])) {
            throw ValidationException::withMessages([
                'items' => 'A held order must keep at least one product when it is completed.',
            ]);
        }
        if ($cartItems === [] && $laborAmount <= 0) {
            throw ValidationException::withMessages([
                'checkout' => 'Add at least one product or enter a labor charge before checkout.',
            ]);
        }

        $fingerprint = hash('sha256', json_encode([
            $cartItems, $laborAmount, $validated['payment_method'] ?? 'cash', $validated['held_order_id'] ?? null,
        ], JSON_THROW_ON_ERROR));
        $replayed = false;
        $sale = DB::transaction(function () use ($validated, $cartItems, $laborAmount, $request, $fingerprint, &$replayed) {
            // Serialize checkout attempts per cashier, including labor-only sales.
            DB::table('users')->where('id', $request->user()->id)->lockForUpdate()->first();
            $existing = SalesTransaction::where('staff_id', $request->user()->id)
                ->where('checkout_key', $validated['checkout_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->checkout_fingerprint, $fingerprint), 409,
                    'This checkout key belongs to a different order.');
                $replayed = true;
                return $existing->load('items.product');
            }
            $subtotal = 0;
            $saleItems = [];
            $heldOrder = null;

            $products = Product::query()
                ->with('activePromotion')
                ->whereIn('product_id', collect($cartItems)->pluck('product_id'))
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $this->ensureBundleComposition($products, $cartItems);

            if (! empty($validated['held_order_id'])) {
                $heldOrder = HeldOrder::query()->lockForUpdate()->findOrFail($validated['held_order_id']);
                $this->authorizeCashierRecord($request, $heldOrder->staff_id);

                if ($heldOrder->status !== 'held') {
                    throw ValidationException::withMessages(['held_order_id' => 'This held order is no longer active.']);
                }
            }

            foreach ($cartItems as $cartItem) {
                $product = $products->get($cartItem['product_id']);
                $quantity = (int) $cartItem['quantity'];

                if (! $product || $product->current_stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => ($product?->name ?? 'A selected product').' no longer has enough stock.',
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

            $total = round($subtotal + $laborAmount, 2);

            $sale = SalesTransaction::create([
                'checkout_key' => $validated['checkout_key'],
                'checkout_fingerprint' => $fingerprint,
                'staff_id' => $request->user()->id,
                'subtotal' => $subtotal,
                'tax_amount' => 0,
                'labor_amount' => $laborAmount,
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

        if (! $replayed) {
            $sale->items->each(fn (SalesItem $item) => $alerts->checkProduct($item->product));
        }

        return response()->json([
            'message' => "Payment processed. Receipt #{$receiptNumber} saved.",
            'sale_id' => $sale->sale_id,
            'total' => (float) $sale->total_sale_amount,
            'receipt' => [
                'number' => $receiptNumber,
                'url' => route($request->user()->role.'.pos.receipts.show', $sale),
                'date' => $sale->sale_date->format('M d, Y h:i A'),
                'cashier' => $request->user()->name,
                'payment_method' => ucfirst($sale->payment_method),
                'subtotal' => (float) $sale->subtotal,
                'tax' => (float) $sale->tax_amount,
                'labor' => (float) $sale->labor_amount,
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

    private function ensureBundleComposition($products, array $items): void
    {
        $quantities = collect($items)
            ->groupBy('product_id')
            ->map(fn ($lines) => $lines->sum('quantity'));

        foreach ($products as $product) {
            $promotion = $product->activePromotion;
            if (! $promotion || $promotion->action_type !== 'promo_bundle' || ! $promotion->bundle_product_id) {
                continue;
            }

            $freeQuantity = (int) ($quantities[$product->product_id] ?? 0);
            $companionQuantity = (int) ($quantities[$promotion->bundle_product_id] ?? 0);

            if ($companionQuantity < $freeQuantity) {
                $companion = Product::query()->find($promotion->bundle_product_id);
                throw ValidationException::withMessages([
                    'items' => "{$product->name} is free only when bundled with "
                        .($companion?->name ?? 'its selected companion product').'.',
                ]);
            }
        }
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
            'labor' => (float) $heldOrder->labor_amount,
            'total' => round(
                (float) $heldOrder->items->sum(fn (HeldOrderItem $item) => $item->quantity * (float) $item->unit_price)
                + (float) $heldOrder->labor_amount,
                2
            ),
            'cancel_url' => route(auth()->user()->role.'.pos.holds.cancel', $heldOrder),
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
