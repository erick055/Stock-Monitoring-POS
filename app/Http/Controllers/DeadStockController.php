<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\SalesItem;
use Illuminate\View\View;

class DeadStockController extends Controller
{
    public function index(): View
    {
        $products = Product::query()
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

        return view('admin.dead-stock', compact(
            'summary',
            'deadStockItems',
            'slowMovingItems',
            'recommendations',
            'scoredProducts'
        ));
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
