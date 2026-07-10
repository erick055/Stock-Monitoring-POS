<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(): View
    {
        $weekStart = CarbonImmutable::now()->startOfWeek();
        $weekEnd = $weekStart->endOfWeek();

        $paidSales = SalesTransaction::query()->where('payment_status', 'paid');
        $totalSales = (float) (clone $paidSales)->sum('total_sale_amount');
        $transactionCount = (clone $paidSales)->count();
        $averageOrderValue = $transactionCount > 0 ? $totalSales / $transactionCount : 0;

        $profit = SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->selectRaw('COALESCE(SUM((sales_items.unit_sale_price - sales_items.unit_cost) * sales_items.quantity), 0) as profit')
            ->value('profit');

        $cost = SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->selectRaw('COALESCE(SUM(sales_items.unit_cost * sales_items.quantity), 0) as cost')
            ->value('cost');

        $summary = [
            'total_sales' => $totalSales,
            'transactions' => $transactionCount,
            'average_order_value' => $averageOrderValue,
            'profit_margin' => ((float) $cost + (float) $profit) > 0 ? ((float) $profit / ((float) $cost + (float) $profit)) * 100 : 0,
        ];

        $bestSellers = SalesItem::query()
            ->select('products.product_id', 'products.name', 'products.sku')
            ->selectRaw('SUM(sales_items.quantity) as units_sold')
            ->selectRaw('SUM(sales_items.line_total) as sales_total')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->groupBy('products.product_id', 'products.name', 'products.sku')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();

        $demand = SalesItem::query()
            ->select('products.name', 'products.category')
            ->selectRaw('SUM(sales_items.quantity) as demand_units')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [now()->subDays(30), now()])
            ->groupBy('products.name', 'products.category')
            ->orderByDesc('demand_units')
            ->limit(5)
            ->get();

        $highestStock = Product::query()->where('is_active', true)->orderByDesc('current_stock')->limit(5)->get();
        $lowestStock = Product::query()->where('is_active', true)->orderBy('current_stock')->limit(5)->get();

        $weeklyRaw = SalesTransaction::query()
            ->selectRaw('DATE(sale_date) as sale_day, SUM(total_sale_amount) as total')
            ->where('payment_status', 'paid')
            ->whereBetween('sale_date', [$weekStart, $weekEnd])
            ->groupBy(DB::raw('DATE(sale_date)'))
            ->pluck('total', 'sale_day');

        $maxDailySales = max((float) $weeklyRaw->max(), 1);
        $weeklySales = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $weeklyRaw, $maxDailySales) {
            $date = $weekStart->addDays($offset);
            $total = (float) ($weeklyRaw[$date->toDateString()] ?? 0);

            return [
                'label' => $date->format('D'),
                'date' => $date->format('M d'),
                'total' => $total,
                'percent' => round(($total / $maxDailySales) * 100),
            ];
        });

        $stockFlow = [
            'added' => InventoryLedger::query()->sum('qty_in'),
            'sold' => SalesItem::query()->sum('quantity'),
            'stock_out' => InventoryLedger::query()->where('reason_code', '!=', 'POS_SALE')->sum('qty_out'),
            'current' => Product::query()->where('is_active', true)->sum('current_stock'),
        ];

        return view('admin.analytics', compact(
            'summary',
            'bestSellers',
            'demand',
            'highestStock',
            'lowestStock',
            'weeklySales',
            'stockFlow'
        ));
    }
}
