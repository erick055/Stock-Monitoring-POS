<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SalesItem;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LowStocksController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
            ->where('is_active', true)
            ->orderBy('current_stock')
            ->orderBy('name')
            ->get();

        $criticalProducts = $products
            ->filter(fn (Product $product) => $product->current_stock <= $product->reorder_level)
            ->values();

        $warningProducts = $products
            ->filter(fn (Product $product) => $product->current_stock > $product->reorder_level
                && $product->current_stock <= ($product->reorder_level * 2))
            ->values();

        $activeAlerts = $criticalProducts
            ->map(fn (Product $product) => $this->formatAlert($product, 'critical'))
            ->merge($warningProducts->map(fn (Product $product) => $this->formatAlert($product, 'warning')))
            ->values();

        $soldLastSevenDays = (int) SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '>=', now()->subDays(7))
            ->sum('sales_items.quantity');

        $fastMoving = SalesItem::query()
            ->select('products.name', 'products.sku', 'products.current_stock')
            ->selectRaw('SUM(sales_items.quantity) as weekly_units')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '>=', now()->subDays(7))
            ->groupBy('products.product_id', 'products.name', 'products.sku', 'products.current_stock')
            ->orderByDesc('weekly_units')
            ->limit(5)
            ->get()
            ->map(function ($item) {
                $dailyDemand = max(((int) $item->weekly_units) / 7, 0.1);

                return [
                    'name' => $item->name,
                    'sku' => $item->sku,
                    'weekly' => number_format($item->weekly_units) . ' units',
                    'turnover' => number_format(((int) $item->weekly_units) / max((int) $item->current_stock, 1), 1) . 'x / wk',
                    'days_left' => ceil(((int) $item->current_stock) / $dailyDemand) . ' days',
                    'status' => 'Fast Moving',
                ];
            });

        $summary = [
            'critical_low' => $criticalProducts->count(),
            'low_warning' => $warningProducts->count(),
            'avg_daily_sales' => round($soldLastSevenDays / 7, 1),
            'avg_weekly_demand' => $soldLastSevenDays,
        ];

        $settings = [
            ['Email Notification', 'Receive low-stock alerts by email'],
            ['Sms Alerts', 'Send urgent warnings'],
            ['Daily Summary', 'Email stock alert summary'],
        ];

        return view('admin.low-stocks', compact('summary', 'activeAlerts', 'fastMoving', 'settings'));
    }

    private function formatAlert(Product $product, string $status): array
    {
        $threshold = max((int) $product->reorder_level, 1);
        $percentage = min(100, round(((int) $product->current_stock / ($threshold * 2)) * 100));

        return [
            'name' => $product->name,
            'sku' => $product->sku,
            'stock' => $product->current_stock,
            'threshold' => $product->reorder_level,
            'status' => $status === 'critical' ? 'Critical' : 'Warning',
            'status_class' => $status,
            'fill' => max(5, $percentage) . '%',
            'actions' => $status === 'critical' ? ['Restock Now', 'Priority'] : ['Monitor', 'Schedule PO'],
        ];
    }
}
