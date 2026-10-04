<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\DeadStockMlModel;
use App\Models\DeadStockMlPrediction;
use App\Models\ProductPromotion;
use App\Models\SalesItem;
use Illuminate\Support\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use App\Services\DeadStockMachineLearning;
use RuntimeException;

class DeadStockController extends Controller
{
    public function index(Request $request): View
    {
        // Scheduled training prepares results; HTTP requests only read them.
        $search = trim((string) $request->query('search'));
        $classification = (string) $request->query('classification', 'all');
        $status = in_array((string) $request->query('status', 'queue'), ['queue', 'archived'], true)
            ? (string) $request->query('status', 'queue')
            : 'queue';
        $perPage = in_array((int) $request->query('per_page', 25), [10, 25, 50, 100], true)
            ? (int) $request->query('per_page', 25)
            : 25;
        $products = Product::query()
            ->with(['activePromotion.administrator', 'activePromotion.bundleProduct', 'deadStockArchivedBy'])
            ->where('is_active', true)
            ->orderByDesc('current_stock')
            ->get();

        $salesLastNinetyDays = $this->soldUnitsByProduct(90);
        $salesLastThirtyDays = $this->soldUnitsByProduct(30);
        $latestSales = $this->latestSaleByProduct();

        $latestMlModel = DeadStockMlModel::query()->where('version', 'like', 'dsml-v2-%')->latest('trained_at')->first();
        $mlPredictions = $latestMlModel
            ? DeadStockMlPrediction::where('model_id', $latestMlModel->id)->get()->keyBy('product_id')
            : collect();
        $scoredProducts = $products
            ->map(function (Product $product) use ($salesLastThirtyDays, $salesLastNinetyDays, $latestSales, $mlPredictions) {
                $item = $this->scoreProduct(
                $product,
                (int) ($salesLastThirtyDays[$product->product_id] ?? 0),
                (int) ($salesLastNinetyDays[$product->product_id] ?? 0),
                $latestSales[$product->product_id] ?? null
                );
                $prediction = $mlPredictions->get($product->product_id);
                $item['ml_prediction'] = $prediction ? [
                    'probability' => (float) $prediction->stagnation_probability,
                    'classification' => $prediction->classification,
                    'factors' => $prediction->factors,
                    'predicted_at' => $prediction->predicted_at,
                ] : null;
                return $item;
            })
            ->sortByDesc('score')
            ->values();

        $queueProducts = $scoredProducts
            ->filter(fn (array $item) => ! $item['is_archived'] && $item['stock'] > 0)
            ->whereIn('classification', ['Dead Stock', 'Slow Moving'])
            ->values();
        $archivedProducts = $scoredProducts->filter(fn (array $item) => $item['is_archived'])->values();
        $deadStockItems = $queueProducts->filter(fn (array $item) => $item['classification'] === 'Dead Stock')->values();
        $slowMovingItems = $queueProducts->filter(fn (array $item) => $item['classification'] === 'Slow Moving')->values();
        $trappedCapital = $deadStockItems->sum('total_cost_raw') + $slowMovingItems->sum('total_cost_raw');

        $summary = [
            ['DEAD STOCK ITEM', number_format($deadStockItems->count()), 'Measured score 70-100 / high risk', 'purple'],
            ['SLOW MOVING', number_format($slowMovingItems->count()), 'Measured score 40-69 / monitor', 'violet'],
            ['TRAPPED CAPITAL', '₱' . number_format($trappedCapital, 2), 'Estimated value tied to idle stock', 'orange'],
            ['ARCHIVED', number_format($archivedProducts->count()), 'Hidden from the active recovery queue', 'cyan'],
        ];

        $recommendations = $this->buildRecommendations($deadStockItems, $slowMovingItems);

        $filteredRiskItems = ($status === 'archived' ? $archivedProducts : $queueProducts)
            ->when($classification === 'dead', fn ($items) => $items->where('classification', 'Dead Stock'))
            ->when($classification === 'slow', fn ($items) => $items->where('classification', 'Slow Moving'))
            ->when($classification === 'healthy', fn ($items) => $items->where('classification', 'Healthy'))
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

        $bundleProducts = Product::query()
            ->where('is_active', true)
            ->where('current_stock', '>', 0)
            ->orderBy('name')
            ->get(['product_id', 'sku', 'name', 'current_stock']);

        $outlookItems = $scoredProducts
            ->filter(fn (array $item) => $item['stock'] > 0)
            ->when($search, fn ($items) => $items->filter(fn (array $item) => str_contains(
                mb_strtolower($item['name'].' '.$item['sku']), mb_strtolower($search)
            )))
            ->sortByDesc(fn (array $item) => $item['ml_prediction']['probability'] ?? -1)
            ->values();
        $outlookItems = new LengthAwarePaginator(
            $outlookItems->forPage(LengthAwarePaginator::resolveCurrentPage('outlook_page'), $perPage)->values(),
            $outlookItems->count(), $perPage, LengthAwarePaginator::resolveCurrentPage('outlook_page'),
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'outlook_page']
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
            'status',
            'perPage',
            'bundleProducts',
            'latestMlModel',
            'outlookItems'
        ));
    }

    public function trainModel(DeadStockMachineLearning $ml): RedirectResponse
    {
        if (! \App\Models\SalesItem::whereHas('sale', fn ($q) => $q->where('payment_status', 'paid'))->exists()) {
            return back()->withErrors(['ml' => 'Not enough POS history. Record paid product sales before training the ML model.']);
        }
        \App\Jobs\RefreshInventoryPredictions::dispatch()->onConnection('ml')->onQueue('ml');
        return back()->with('success', 'Model refresh queued. Predictions will update after the background worker finishes.');
    }

    public function applyPromotion(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'action_type' => ['required', Rule::in(['discount', 'promo_bundle'])],
            'discount_percent' => ['nullable', 'required_unless:action_type,promo_bundle', 'numeric', 'min:1', 'max:90'],
            'bundle_product_id' => [
                'nullable',
                'required_if:action_type,promo_bundle',
                'integer',
                Rule::exists('products', 'product_id')->where(fn ($query) => $query
                    ->where('is_active', true)
                    ->where('current_stock', '>', 0)),
                Rule::notIn([$product->product_id]),
            ],
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
            $isBundle = $validated['action_type'] === 'promo_bundle';
            $discount = $isBundle ? 100 : round((float) $validated['discount_percent'], 2);
            $promotionalPrice = $isBundle
                ? 0
                : round((float) $lockedProduct->unit_price * (1 - ($discount / 100)), 2);

            ProductPromotion::query()
                ->where('product_id', $lockedProduct->product_id)
                ->where('status', 'active')
                ->update(['status' => 'ended', 'ended_at' => now()]);

            return ProductPromotion::create([
                'product_id' => $lockedProduct->product_id,
                'bundle_product_id' => $isBundle ? $validated['bundle_product_id'] : null,
                'applied_by' => $request->user()->id,
                'action_type' => $validated['action_type'],
                'discount_percent' => $discount,
                'original_price' => $lockedProduct->unit_price,
                'promotional_price' => $promotionalPrice,
                'bundle_note' => null,
                'status' => 'active',
                'started_at' => now(),
            ]);
        });

        return back()->with('success', $promotion->action_type === 'promo_bundle'
            ? "Promo bundle approved. {$product->name} now appears as a free item in POS."
            : "{$promotion->action_label} approved for {$product->name} at {$promotion->discount_percent}% off.");
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

    public function archive(Request $request, Product $product): RedirectResponse
    {
        $validated = $request->validate([
            'archive_note' => ['nullable', 'string', 'max:500'],
        ]);

        if ($product->dead_stock_archived_at) {
            return back()->with('success', "{$product->name} is already archived.");
        }

        $analysis = $this->scoreProduct(
            $product->load(['activePromotion.administrator', 'activePromotion.bundleProduct', 'deadStockArchivedBy']),
            (int) ($this->soldUnitsByProduct(30)[$product->product_id] ?? 0),
            (int) ($this->soldUnitsByProduct(90)[$product->product_id] ?? 0),
            $this->latestSaleByProduct()[$product->product_id] ?? null
        );

        if (! in_array($analysis['classification'], ['Dead Stock', 'Slow Moving'], true)) {
            throw ValidationException::withMessages([
                'archive' => 'Only products currently classified as Dead Stock or Slow Moving can be archived from this page.',
            ]);
        }

        $product->update([
            'dead_stock_archived_at' => now(),
            'dead_stock_archived_by' => $request->user()->id,
            'dead_stock_archive_note' => trim((string) ($validated['archive_note'] ?? '')) ?: null,
        ]);

        return back()->with('success', "{$product->name} was archived from the active dead-stock queue. Inventory and POS availability were not changed.");
    }

    public function restore(Product $product): RedirectResponse
    {
        if (! $product->dead_stock_archived_at) {
            return back()->with('success', "{$product->name} is already in the active queue.");
        }

        $product->update([
            'dead_stock_archived_at' => null,
            'dead_stock_archived_by' => null,
            'dead_stock_archive_note' => null,
        ]);

        return redirect()->route('admin.dead-stock')->with('success', "{$product->name} was restored from the archive and will appear when it matches the active dead-stock queue rules.");
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

    public function scoreProduct(Product $product, int $monthlyUnits, int $quarterUnits, ?string $latestSale): array
    {
        $totalCost = (float) $product->unit_cost * (int) $product->current_stock;
        $inventoryAgeDays = max(0, (int) $product->created_at?->diffInDays(now()));
        $daysSinceLastSale = $latestSale ? max(0, (int) Carbon::parse($latestSale)->diffInDays(now())) : null;
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
                'action_type' => $product->activePromotion->action_type,
                'discount_percent' => (float) $product->activePromotion->discount_percent,
                'promotional_price' => (float) $product->activePromotion->promotional_price,
                'bundle_note' => $product->activePromotion->bundle_note,
                'bundle_product' => $product->activePromotion->bundleProduct ? [
                    'id' => $product->activePromotion->bundleProduct->product_id,
                    'name' => $product->activePromotion->bundleProduct->name,
                    'sku' => $product->activePromotion->bundleProduct->sku,
                ] : null,
                'administrator' => $product->activePromotion->administrator?->name ?? 'Administrator',
            ] : null,
            'is_archived' => $product->dead_stock_archived_at !== null,
            'archived_at' => $product->dead_stock_archived_at?->format('M d, Y h:i A'),
            'archived_by' => $product->deadStockArchivedBy?->name ?? 'Administrator',
            'archive_note' => $product->dead_stock_archive_note,
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
            'Dead Stock' => 'Apply a targeted discount, bundle with fast-moving items, and stop reordering.',
            'Slow Moving' => 'Monitor weekly, review pricing, and improve shelf placement.',
            default => 'Continue normal monitoring.',
        };
    }

    private function buildRecommendations($deadStockItems, $slowMovingItems): array
    {
        if ($deadStockItems->isEmpty() && $slowMovingItems->isEmpty()) {
            return ['Measured scan result: inventory movement looks healthy. Continue monitoring POS sales and stock aging weekly.'];
        }

        $recommendations = [];

        if ($deadStockItems->isNotEmpty()) {
            $topDeadStock = $deadStockItems->first();
            $recommendations[] = "Rule-based priority: {$topDeadStock['name']} has a {$topDeadStock['score']}/100 dead-stock risk score. {$topDeadStock['recommendation']}";
            $recommendations[] = 'Pause reordering for SKUs classified as Dead Stock until existing inventory is reduced.';
        }

        if ($slowMovingItems->isNotEmpty()) {
            $topSlowMoving = $slowMovingItems->first();
            $recommendations[] = "Rule-based monitor: {$topSlowMoving['name']} is slow moving at {$topSlowMoving['velocity']}. {$topSlowMoving['recommendation']}";
        }

        $recommendations[] = 'Use POS sales history weekly to validate if the recovery action improves turnover.';

        return $recommendations;
    }
}
