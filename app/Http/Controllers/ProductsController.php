<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductsController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $category = trim((string) $request->query('category'));
        $sort = (string) $request->query('sort', 'name');

        $sorts = [
            'name' => ['name', 'asc'],
            'newest' => ['created_at', 'desc'],
            'stock_high' => ['current_stock', 'desc'],
            'stock_low' => ['current_stock', 'asc'],
            'price_high' => ['unit_price', 'desc'],
            'price_low' => ['unit_price', 'asc'],
        ];
        [$sortColumn, $sortDirection] = $sorts[$sort] ?? $sorts['name'];

        $baseQuery = Product::query()->where('is_active', true);
        $products = (clone $baseQuery)
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('sku', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('category', 'like', "%{$search}%");
            }))
            ->when($category, fn ($query) => $query->where('category', $category))
            ->orderBy($sortColumn, $sortDirection)
            ->paginate(10)
            ->withQueryString();

        $allProducts = (clone $baseQuery)->get();
        $categories = (clone $baseQuery)->whereNotNull('category')->where('category', '<>', '')
            ->distinct()->orderBy('category')->pluck('category');
        $averageMargin = $allProducts->filter(fn ($product) => (float) $product->unit_price > 0)
            ->avg(fn ($product) => (((float) $product->unit_price - (float) $product->unit_cost) / (float) $product->unit_price) * 100) ?? 0;

        $summary = [
            'total_products' => $allProducts->count(),
            'categories' => $categories->count(),
            'average_margin' => $averageMargin,
            'total_value' => $allProducts->sum(fn ($product) => $product->current_stock * (float) $product->unit_cost),
        ];

        $view = $request->user()->role === 'admin' ? 'admin.products' : 'staff.products';

        return view($view, compact('products', 'categories', 'summary', 'search', 'category', 'sort'));
    }
}
