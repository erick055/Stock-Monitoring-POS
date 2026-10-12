<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SalesItem;
use App\Services\InventoryLiveService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductsController extends Controller
{
    public function index(Request $request, InventoryLiveService $liveInventory): View
    {
        $search = trim((string) $request->query('search'));
        $category = trim((string) $request->query('category'));
        $sort = (string) $request->query('sort', 'name');

        $sorts = [
            'name' => ['name', 'asc'],
            'name_desc' => ['name', 'desc'],
            'newest' => ['created_at', 'desc'],
            'oldest' => ['created_at', 'asc'],
            'stock_high' => ['current_stock', 'desc'],
            'stock_low' => ['current_stock', 'asc'],
            'price_high' => ['unit_price', 'desc'],
            'price_low' => ['unit_price', 'asc'],
        ];
        if (! array_key_exists($sort, $sorts)) {
            $sort = 'name';
        }
        [$sortColumn, $sortDirection] = $sorts[$sort];

        $baseQuery = Product::query()->where('is_active', true);
        $products = (clone $baseQuery)
            ->with([
                'activePromotion.bundleProduct',
                'activePromotion.administrator',
                'deadStockArchivedBy',
                'supplierPrices.supplier',
                'latestLedger.user',
            ])
            ->withCount(['ledgers', 'supplierPrices'])
            ->withSum(['saleItems as units_sold' => fn ($query) => $query->whereHas('sale', fn ($sale) => $sale->where('payment_status', 'paid'))], 'quantity')
            ->withSum(['saleItems as sales_revenue' => fn ($query) => $query->whereHas('sale', fn ($sale) => $sale->where('payment_status', 'paid'))], 'line_total')
            ->withSum(['customerReturns as returned_units' => fn ($query) => $query->where('status', 'approved')], 'quantity')
            ->withSum(['damagedGoods as damaged_units' => fn ($query) => $query->whereNotIn('status', ['pending', 'rejected'])], 'quantity')
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%")
                    ->orWhere('shelf_location', 'like', "%{$search}%");
            }))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->orderBy($sortColumn, $sortDirection)
            ->orderBy('product_id')
            ->paginate(10)
            ->withQueryString();

        $now = now();
        $movement = SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->whereIn('sales_items.product_id', $products->pluck('product_id'))
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '<=', $now)
            ->select('sales_items.product_id')
            ->selectRaw('SUM(CASE WHEN sales_transactions.sale_date >= ? THEN sales_items.quantity ELSE 0 END) as monthly_units', [$now->copy()->subDays(30)])
            ->selectRaw('SUM(CASE WHEN sales_transactions.sale_date >= ? THEN sales_items.quantity ELSE 0 END) as quarter_units', [$now->copy()->subDays(90)])
            ->selectRaw('MAX(sales_transactions.sale_date) as latest_sale')
            ->groupBy('sales_items.product_id')->get()->keyBy('product_id');
        $deadStockStatuses = $products->getCollection()->mapWithKeys(function (Product $product) use ($movement) {
            if ($product->current_stock <= 0) {
                return [$product->product_id => 'No stock to assess'];
            }

            $sales = $movement->get($product->product_id);
            $analysis = app(DeadStockController::class)->scoreProduct($product,
                (int) ($sales?->monthly_units ?? 0), (int) ($sales?->quarter_units ?? 0), $sales?->latest_sale);

            return [$product->product_id => $analysis['classification']];
        });

        $allProducts = (clone $baseQuery)->get();
        $categories = (clone $baseQuery)->whereNotNull('category')->where('category', '<>', '')
            ->distinct()->orderBy('category')->pluck('category');
        $averageProfit = $allProducts
            ->avg(fn ($product) => (float) $product->unit_price - (float) $product->unit_cost) ?? 0;

        $summary = [
            'total_products' => $allProducts->count(),
            'categories' => $categories->count(),
            'average_profit' => $averageProfit,
            'total_value' => $allProducts->sum(fn ($product) => $product->current_stock * (float) $product->unit_cost),
        ];

        $view = $request->user()->role === 'admin' ? 'admin.products' : 'staff.products';
        $inventoryVersion = $liveInventory->version();

        return view($view, compact('products', 'categories', 'summary', 'search', 'category', 'sort', 'inventoryVersion', 'deadStockStatuses'));
    }
}
