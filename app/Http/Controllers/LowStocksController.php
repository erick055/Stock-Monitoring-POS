<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SalesItem;
use App\Models\StockAlertDelivery;
use App\Services\LowStockAlertService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LowStocksController extends Controller
{
    public function index(Request $request, LowStockAlertService $alerts): View
    {
        $search = trim((string) $request->query('search'));
        $status = (string) $request->query('status', 'all');
        $perPage = in_array((int) $request->query('per_page', 25), [10, 25, 50, 100], true)
            ? (int) $request->query('per_page', 25)
            : 25;

        $products = Product::query()->where('is_active', true)->get();

        $criticalProducts = $products
            ->filter(fn (Product $product) => $product->current_stock <= $product->reorder_level)
            ->values();

        $warningProducts = $products
            ->filter(fn (Product $product) => $product->current_stock > $product->reorder_level
                && $product->current_stock <= ($product->reorder_level * 2))
            ->values();

        $activeAlerts = Product::query()
            ->where('is_active', true)
            ->whereRaw('current_stock <= (reorder_level * 2)')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            }))
            ->when($status === 'critical', fn ($query) => $query->whereColumn('current_stock', '<=', 'reorder_level'))
            ->when($status === 'warning', fn ($query) => $query
                ->whereColumn('current_stock', '>', 'reorder_level')
                ->whereRaw('current_stock <= (reorder_level * 2)'))
            ->orderBy('current_stock')
            ->orderBy('name')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Product $product) => $this->formatAlert(
                $product,
                $product->current_stock <= $product->reorder_level ? 'critical' : 'warning'
            ));

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

        $settings = $alerts->settings();
        if (! $settings->notification_email) {
            $settings->update(['notification_email' => $request->user()->email]);
        }
        $deliveries = StockAlertDelivery::query()->where('channel', 'email')->latest()->limit(20)->get();

        return view('admin.low-stocks', compact(
            'summary', 'activeAlerts', 'fastMoving', 'settings', 'deliveries',
            'search', 'status', 'perPage'
        ));
    }

    public function updateSettings(Request $request, LowStockAlertService $alerts): RedirectResponse
    {
        $validated = $request->validate([
            'email_enabled' => ['required', 'boolean'],
            'daily_summary_enabled' => ['required', 'boolean'],
            'notification_email' => ['nullable', 'required_if:email_enabled,1', 'required_if:daily_summary_enabled,1', 'email', 'max:255'],
            'daily_summary_time' => ['required', 'date_format:H:i'],
        ]);

        $alerts->settings()->update($validated);

        return back()->with('success', 'Stock alert notification settings saved.');
    }

    public function runNow(LowStockAlertService $alerts): RedirectResponse
    {
        // This is an explicit delivery test initiated by an administrator.
        // Scheduled and inventory-triggered checks remain deduplicated.
        $sent = $alerts->checkAll(force: true);

        return back()->with('success', "Stock alert check completed. {$sent} new notification(s) sent.");
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
