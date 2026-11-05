<?php

namespace App\Http\Controllers;

use App\Services\InventoryLiveService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryLiveController extends Controller
{
    public function __invoke(InventoryLiveService $inventory, Request $request): JsonResponse
    {
        $products = $inventory->products();

        if ($request->query('view') === 'balances') {
            return response()->json([
                'version' => $inventory->version($products),
                'balances' => $products->map(fn ($product) => [
                    'id' => $product->product_id, 'stock' => (int) $product->current_stock,
                    'reorder' => (int) $product->reorder_level, 'status' => $product->stock_status,
                    'unitCost' => (float) $product->unit_cost, 'unitPrice' => (float) $product->unit_price,
                ])->values(),
                'summary' => [
                    'total_sku' => $products->count(), 'total_units' => (int) $products->sum('current_stock'),
                    'critical_low' => $products->where('stock_status', 'critical')->count(),
                    'stock_value' => $products->sum(fn ($product) => $product->current_stock * (float) $product->unit_cost),
                    'categories' => $products->pluck('category')->filter(fn ($value) => filled($value))->unique()->count(),
                    'average_profit' => $products->avg(fn ($product) => (float) $product->unit_price - (float) $product->unit_cost) ?? 0,
                ],
                'updated_at' => now()->toIso8601String(),
            ]);
        }

        return response()->json([
            'version' => $inventory->version($products),
            'products' => $inventory->posProducts($products),
            'updated_at' => now()->toIso8601String(),
        ]);
    }
}
