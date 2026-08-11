<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductPromotion;
use App\Models\SalesItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class DeadStockController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));
        $classification = (string) $request->query('classification', 'all');
        $perPage = in_array((int) $request->query('per_page', 25), [10, 25, 50, 100], true)
            ? (int) $request->query('per_page', 25)
            : 25;
        $products = Product::query()
            ->with('activePromotion.administrator')
            ->where('is_active', true)
            ->where('current_stock', '>', 0)
            ->orderByDesc('current_stock')
            ->get();

        $salesLastNinetyDays = $this->soldUnitsByProduct(90);
        $salesLastThirtyDays = $this->soldUnitsByProduct(30);
        $latestSales = $this->latestSaleByProduct();

        $scoredProducts = $products
            ->map(fn (Product $product) => $this->scoreProduct(
                $product,
                (int) ($salesLastThirtyDays[$product->product_id] ?? 0),
                (int) ($salesLastNinetyDays[$product->product_id] ?? 0),
                $latestSales[$product->product_id] ?? null
            ))
            ->sortByDesc('score')
            ->values();

        $deadStockItems = $scoredProducts->filter(fn (array $item) => $item['classification'] === 'Dead Stock')->values();
        $slowMovingItems = $scoredProducts->filter(fn (array $item) => $item['classification'] === 'Slow Moving')->values();
        $trappedCapital = $deadStockItems->sum('total_cost_raw') + $slowMovingItems->sum('total_cost_raw');

        $summary = [
            ['DEAD STOCK ITEM', number_format($deadStockItems->count()), 'AI score 70-100 / high risk', 'purple'],
            ['SLOW MOVING', number_format($slowMovingItems->count()), 'AI score 40-69 / monitor', 'violet'],
            ['TRAPPED CAPITAL', '₱' . number_format($trappedCapital, 2), 'Estimated value tied to idle stock', 'orange'],
        ];

        $recommendations = $this->buildRecommendations($deadStockItems, $slowMovingItems);

        $filteredRiskItems = $scoredProducts
            ->whereIn('classification', ['Dead Stock', 'Slow Moving'])
            ->when($classification === 'dead', fn ($items) => $items->where('classification', 'Dead Stock'))
            ->when($classification === 'slow', fn ($items) => $items->where('classification', 'Slow Moving'))
            ->when($search, fn ($items) => $items->filter(fn (array $item) => str_contains(
                mb_strtolower($item['name'].' '.$item['sku']),
                mb_strtolower($search)
            )))
            ->values();

        $page = LengthAwarePaginator::resolveCurrentPage();
        $riskItems = new LengthAwarePaginator(
            $filteredRiskItems->forPage($page, $perPage)->values(),
            $filteredRiskItems->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('admin.dead-stock', compact(
            'summary',
            'deadStockItems',
            'slowMovingItems',
            'recommendations',
            'scoredProducts',
            'riskItems',
            'search',
            'classification',
            'perPage'
        ));
    }

    public function applyPromotion(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', Rule::in(['discount', 'promo_bundle', 'clearance'])],
            'discount_percent' => ['required', 'numeric', 'min:1', 'max:90'],
            'bundle_note' => ['nullable', 'required_if:action_type,promo_bundle', 'string', 'max:160'],
        ]);

        $analysis = $this->scoreProduct(
            $product,
            (int) ($this->soldUnitsByProduct(30)[$product->product_id] ?? 0),
            (int) ($this->soldUnitsByProduct(90)[$product->product_id] ?? 0),
            $this->latestSaleByProduct()[$product->product_id] ?? null
        );

        if (! in_array($analysis['classification'], ['Dead Stock', 'Slow Moving'], true)) {
            throw ValidationException::withMessages([
                'promotion' => 'This product is no longer classified as unlikely to sell soon. Refresh the AI inventory scan before applying an offer.',
            ]);
        }

        $promotion = DB::transaction(function () use ($product, $request, $validated) {
            $lockedProduct = Product::query()->lockForUpdate()->findOrFail($product->product_id);
            $discount = round((float) $validated['discount_percent'], 2);
            $promotionalPrice = round((float) $lockedProduct->unit_price * (1 - ($discount / 100)), 2);

            ProductPromotion::query()
                ->where('product_id', $lockedProduct->product_id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ended_at' => now()]);

            return ProductPromotion::create([
                'product_id' => $lockedProduct->product_id,
                'applied_by' => $request->user()->id,
                'action_type' => $validated['action_type'],
                'discount_percent' => $discount,
                'original_price' => $lockedProduct->unit_price,
                'promotional_price' => $promotionalPrice,
                'bundle_note' => $validated['bundle_note'] ?? null,
                'status' => 'active',
                'started_at' => now(),
            ]);
        });

        return back()->with('success', "{$promotion->action_label} approved for {$product->name} at {$promotion->discount_percent}% off.");
    }

    public function endPromotion(Product $product): RedirectResponse
    {
        $ended = ProductPromotion::query()
            ->where('product_id', $product->product_id)
            ->where('status', 'active')
            ->update(['status' => 'ended', 'ended_at' => now()]);

        return back()->with('success', $ended
            ? "The active offer for {$product->name} was ended. The POS now uses its regular price."
            : "{$product->name} has no active offer to end.");
    }

    private function soldUnitsByProduct(int $days): array
    {
        return SalesItem::query()
            ->selectRaw('sales_items.product_id, SUM(sales_items.quantity) as units_sold')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '>=', now()->subDays($days))
            ->groupBy('sales_items.product_id')
            ->pluck('units_sold', 'product_id')
            ->map(fn ($value) => (int) $value)
            ->all();
    }

    private function latestSaleByProduct(): array
    {
        return SalesItem::query()
            ->selectRaw('sales_items.product_id, MAX(sales_transactions.sale_date) as latest_sale')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->groupBy('sales_items.product_id')
            ->pluck('latest_sale', 'product_id')
            ->all();
    }

    private function scoreProduct(Product $product, int $monthlyUnits, int $quarterUnits, ?string $latestSale): array
    {
        $totalCost = (float) $product->unit_cost * (int) $product->current_stock;
        $inventoryAgeDays = max(0, (int) $product->created_at?->diffInDays(now()));
        $daysSinceLastSale = $latestSale ? max(0, (int) now()->diffInDays($latestSale)) : null;
        $score = 0;
        $reasons = [];

        if ($quarterUnits === 0) {
            $score += 50;
            $reasons[] = 'No POS sales recorded in the last 90 days.';
        }

        if ($monthlyUnits === 0) {
            $score += 20;
            $reasons[] = 'No demand detected in the last 30 days.';
        } elseif ($monthlyUnits <= 3) {
            $score += 20;
            $reasons[] = "Only {$monthlyUnits} unit(s) sold in the last 30 days.";
        }

        if ($quarterUnits > 0 && $quarterUnits <= 5) {
            $score += 10;
            $reasons[] = "Only {$quarterUnits} unit(s) sold in the last 90 days.";
        }

        if ($product->current_stock > max($product->reorder_level * 2, 1)) {
            $score += 15;
            $reasons[] = 'Stock level is high compared with reorder level.';
        }

        if ($totalCost >= 5000) {
            $score += 15;
            $reasons[] = 'High trapped capital based on unit cost and current stock.';
        }

        if ($inventoryAgeDays >= 90) {
            $score += 10;
            $reasons[] = "Inventory has been stored for {$inventoryAgeDays} days.";
        }

        $score = min($score, 100);
        $classification = match (true) {
            $score >= 70 => 'Dead Stock',
            $score >= 40 => 'Slow Moving',
            default => 'Healthy',
        };

        return [
            'product_id' => $product->product_id,
            'name' => $product->name,
            'sku' => $product->sku,
            'stock' => $product->current_stock,
            'monthly_units' => $monthlyUnits,
            'quarter_units' => $quarterUnits,
            'score' => $score,
            'score_width' => max(6, $score) . '%',
            'classification' => $classification,
            'classification_class' => strtolower(str_replace(' ', '-', $classification)),
            'total_cost' => '₱' . number_format($totalCost, 2),
            'total_cost_raw' => $totalCost,
            'unit_price' => (float) $product->unit_price,
            'active_promotion' => $product->activePromotion ? [
                'label' => $product->activePromotion->action_label,
                'discount_percent' => (float) $product->activePromotion->discount_percent,
                'promotional_price' => (float) $product->activePromotion->promotional_price,
                'bundle_note' => $product->activePromotion->bundle_note,
                'administrator' => $product->activePromotion->administrator?->name ?? 'Administrator',
            ] : null,
            'age' => $product->created_at?->diffForHumans(null, true) . ' in inventory',
            'last_sale' => $daysSinceLastSale === null ? 'No sale recorded' : "{$daysSinceLastSale} day(s) ago",
            'velocity' => number_format($monthlyUnits) . ' units / month',
            'note' => 'Monthly Velocity',
            'reasons' => $reasons ?: ['Product movement is currently healthy.'],
            'recommendation' => $this->recommendationFor($classification),
        ];
    }

    private function recommendationFor(string $classification): string
    {
        return match ($classification) {
            'Dead Stock' => 'Apply clearance discount, bundle with fast-moving items, and stop reordering.',
            'Slow Moving' => 'Monitor weekly, review pricing, and improve shelf placement.',
            default => 'Continue normal monitoring.',
        };
    }

    private function buildRecommendations($deadStockItems, $slowMovingItems): array
    {
        if ($deadStockItems->isEmpty() && $slowMovingItems->isEmpty()) {
            return ['AI scan result: inventory movement looks healthy. Continue monitoring POS sales and stock aging weekly.'];
        }

        $recommendations = [];

        if ($deadStockItems->isNotEmpty()) {
            $topDeadStock = $deadStockItems->first();
            $recommendations[] = "AI priority: {$topDeadStock['name']} has a {$topDeadStock['score']}/100 dead-stock risk score. {$topDeadStock['recommendation']}";
            $recommendations[] = 'Pause reordering for SKUs classified as Dead Stock until existing inventory is reduced.';
        }

        if ($slowMovingItems->isNotEmpty()) {
            $topSlowMoving = $slowMovingItems->first();
            $recommendations[] = "AI monitor: {$topSlowMoving['name']} is slow moving at {$topSlowMoving['velocity']}. {$topSlowMoving['recommendation']}";
        }

        $recommendations[] = 'Use POS sales history weekly to validate if the recovery action improves turnover.';

        return $recommendations;
    }
}
