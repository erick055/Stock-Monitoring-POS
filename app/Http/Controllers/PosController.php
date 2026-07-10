<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
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
        $products = Product::query()
            ->where('is_active', true)
            ->where('current_stock', '>', 0)
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->map(fn (Product $product) => [
                'id' => $product->product_id,
                'sku' => $product->sku,
                'name' => $product->name,
                'price' => (float) $product->unit_price,
                'category' => $product->category ?: 'Uncategorized',
                'stock' => $product->current_stock,
            ]);

        $categories = $products->pluck('category')->unique()->values();

        return view('staff.pos', compact('products', 'categories'));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', Rule::exists('products', 'product_id')->where('is_active', true)],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'payment_method' => ['nullable', 'string', 'max:50'],
        ]);

        $sale = DB::transaction(function () use ($validated, $request) {
            $subtotal = 0;
            $saleItems = [];

            foreach ($validated['items'] as $cartItem) {
                $product = Product::query()->lockForUpdate()->findOrFail($cartItem['product_id']);
                $quantity = (int) $cartItem['quantity'];

                if ($product->current_stock < $quantity) {
                    throw ValidationException::withMessages([
                        'items' => "{$product->name} only has {$product->current_stock} stock left.",
                    ]);
                }

                $lineTotal = (float) $product->unit_price * $quantity;
                $subtotal += $lineTotal;

                $product->update(['current_stock' => $product->current_stock - $quantity]);

                $saleItems[] = [
                    'product' => $product,
                    'quantity' => $quantity,
                    'unit_sale_price' => (float) $product->unit_price,
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

            return $sale->load('items.product');
        });

        return response()->json([
            'message' => "Payment processed. Receipt #{$sale->sale_id} saved.",
            'sale_id' => $sale->sale_id,
            'total' => (float) $sale->total_sale_amount,
        ], 201);
    }
}
