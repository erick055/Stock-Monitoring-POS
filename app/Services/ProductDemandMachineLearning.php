<?php

namespace App\Services;

use App\Models\Product;
use App\Models\SalesItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class ProductDemandMachineLearning
{
    private const RESULT_KEY = 'product-demand:latest-v2';

    public function current(): array
    {
        $result = Cache::get(self::RESULT_KEY);
        if ($result) return $result;
        // No sales-history scan or training in an HTTP request.
        $items = Product::where('is_active', true)->orderBy('name')->get()->map(fn ($product) => [
            'product_id' => $product->product_id, 'name' => $product->name, 'sku' => $product->sku,
            'stock' => $product->current_stock, 'recent_units' => 0, 'predicted_units' => null,
            'trend' => 'Waiting for history', 'priority_rank' => null, 'stock_shortfall' => null,
            'recommendation' => 'Waiting for the scheduled model update.',
        ])->all();
        return ['available' => false, 'items' => $items, 'model' => 'Local ridge regression',
            'training_samples' => 0, 'validation_samples' => 0, 'validation_mae' => null, 'baseline_mae' => null,
            'coefficients' => null, 'updated_at' => now()->toIso8601String(),
            'message' => 'Predictions are prepared in the background. Waiting for the next scheduled update.'];
    }

    public function refresh(): array
    {
        $now = CarbonImmutable::now();
        $products = Product::where('is_active', true)->orderBy('name')->get();
        $sales = SalesItem::query()->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [$now->subDays(730), $now])
            ->orderBy('sales_transactions.sale_date')
            ->get(['sales_items.product_id', 'sales_items.quantity', 'sales_items.sale_id', 'sales_transactions.sale_date']);
        // Include quantities and timestamps: corrections invalidate cached training too.
        $key = 'product-demand-ridge-v2-'.hash('sha256', $now->toDateString().$products->toJson().$sales->toJson());

        $result = Cache::remember($key, now()->addHour(), fn () => $this->build($products, $sales, $now));
        Cache::forever(self::RESULT_KEY, $result);
        return $result;
    }

    private function build(Collection $products, Collection $sales, CarbonImmutable $now): array
    {
        $grouped = $sales->groupBy('product_id');
        $samples = [];
        if ($sales->isNotEmpty()) {
            $first = CarbonImmutable::parse($sales->first()->sale_date)->startOfDay();
            for ($cutoff = $first->addDays(90); $cutoff->lte($now->subDays(30)->startOfDay()); $cutoff = $cutoff->addDays(30)) {
                foreach ($products as $product) {
                    if ($product->created_at->gt($cutoff->subDays(90))) continue;
                    $history = $grouped->get($product->product_id, collect());
                    $before = $history->filter(fn ($row) => CarbonImmutable::parse($row->sale_date)->lte($cutoff));
                    if ($before->isEmpty()) continue;
                    $samples[] = ['date' => $cutoff, 'x' => $this->features($before, $cutoff),
                        'y' => $this->units($history, $cutoff, $cutoff->addDays(30))];
                }
            }
        }
        $model = null;
        $mae = $baselineMae = null;
        $dates = collect($samples)->pluck('date')->unique(fn ($date) => $date->toDateString())->values();
        $validationDate = $dates->get((int) floor($dates->count() * .8));
        $training = $validation = [];
        if ($validationDate) {
            // Purge overlapping target windows from the chronological holdout.
            $training = array_values(array_filter($samples, fn ($s) => $s['date']->addDays(30)->lte($validationDate)));
            $validation = array_values(array_filter($samples, fn ($s) => $s['date']->gte($validationDate)));
        }
        if (count($training) >= 20 && count($validation) >= 5 && $dates->count() >= 4) {
            $evaluationModel = $this->fit($training);
            $mae = array_sum(array_map(fn ($s) => abs($this->predict($evaluationModel, $s['x']) - $s['y']), $validation)) / count($validation);
            $baselineMae = array_sum(array_map(fn ($s) => abs($s['x'][0] - $s['y']), $validation)) / count($validation);
            $model = $this->fit($samples);
        }
        $items = $products->map(function ($product) use ($grouped, $now, $model) {
            $history = $grouped->get($product->product_id, collect());
            $features = $this->features($history, $now);
            $eligible = $model && $product->created_at->lte($now->subDays(90)) && $history->pluck('sale_id')->unique()->count() >= 3;
            $prediction = $eligible ? (int) round($this->predict($model, $features)) : null;
            return ['product_id' => $product->product_id, 'name' => $product->name, 'sku' => $product->sku,
                'stock' => $product->current_stock, 'recent_units' => (int) $features[0], 'predicted_units' => $prediction,
                'trend' => $prediction === null ? 'Waiting for history' : ($prediction > $features[0] ? 'Rising' : ($prediction < $features[0] ? 'Falling' : 'Steady'))];
        })->sort(function ($a, $b) {
            return (($b['predicted_units'] ?? -1) <=> ($a['predicted_units'] ?? -1)) ?: strcmp($a['sku'], $b['sku']);
        })->values()->map(function ($item, $index) {
            $item['priority_rank'] = ($item['predicted_units'] ?? 0) > 0 ? $index + 1 : null;
            $item['stock_shortfall'] = $item['predicted_units'] === null ? null : max(0, $item['predicted_units'] - $item['stock']);
            $item['recommendation'] = match (true) {
                $item['predicted_units'] === null => 'More sales history is needed to identify future demand.',
                $item['predicted_units'] === 0 => 'No unit sales predicted in the next 30 days; review before reordering.',
                $item['stock_shortfall'] > 0 => 'Review replenishment: estimated sales exceed current stock by '.$item['stock_shortfall'].' units.',
                default => 'Current stock covers estimated sales; monitor before reordering.',
            };
            return $item;
        })->all();

        return ['available' => $model !== null, 'items' => $items, 'model' => 'Local ridge regression',
            'training_samples' => count($samples), 'validation_samples' => count($validation),
            'validation_mae' => $mae, 'baseline_mae' => $baselineMae, 'coefficients' => $model,
            'updated_at' => $now->toIso8601String(),
            'message' => $model ? 'Estimates from paid POS sales; stock shortages and seasonality can affect results.'
                : 'Waiting for enough sales history: at least 20 training snapshots and 5 later validation snapshots across multiple periods.'];
    }

    private function units(Collection $sales, CarbonImmutable $from, CarbonImmutable $to): float
    {
        return (float) $sales->filter(fn ($s) => CarbonImmutable::parse($s->sale_date)->gt($from)
            && CarbonImmutable::parse($s->sale_date)->lte($to))->sum('quantity');
    }

    private function features(Collection $history, CarbonImmutable $cutoff): array
    {
        $recent = $history->filter(fn ($s) => CarbonImmutable::parse($s->sale_date)->gt($cutoff->subDays(30)));
        $last = $history->last();
        return [$this->units($history, $cutoff->subDays(30), $cutoff),
            $this->units($history, $cutoff->subDays(60), $cutoff->subDays(30)),
            $this->units($history, $cutoff->subDays(90), $cutoff->subDays(60)),
            (float) $recent->pluck('sale_id')->unique()->count(),
            $last ? min(365, CarbonImmutable::parse($last->sale_date)->diffInDays($cutoff)) : 365.0];
    }

    private function fit(array $samples): array
    {
        $means = $scales = [];
        foreach (range(0, 4) as $i) {
            $values = array_column(array_column($samples, 'x'), $i);
            $means[$i] = array_sum($values) / count($values);
            $scales[$i] = max(.0001, sqrt(array_sum(array_map(fn ($v) => ($v - $means[$i]) ** 2, $values)) / count($values)));
        }
        $matrix = array_fill(0, 6, array_fill(0, 7, 0.0));
        foreach ($samples as $sample) {
            $x = [1.0, ...$this->standardize($sample['x'], $means, $scales)];
            foreach ($x as $i => $a) {
                foreach ($x as $j => $b) $matrix[$i][$j] += $a * $b;
                $matrix[$i][6] += $a * $sample['y'];
            }
        }
        foreach (range(1, 5) as $i) $matrix[$i][$i] += 1.0;
        // Solve ridge normal equations with partial pivoting.
        foreach (range(0, 5) as $i) {
            $pivot = $i;
            foreach (range($i, 5) as $j) if (abs($matrix[$j][$i]) > abs($matrix[$pivot][$i])) $pivot = $j;
            [$matrix[$i], $matrix[$pivot]] = [$matrix[$pivot], $matrix[$i]];
            $divisor = $matrix[$i][$i];
            foreach (range($i, 6) as $j) $matrix[$i][$j] /= $divisor;
            foreach (range(0, 5) as $row) {
                if ($row === $i) continue;
                $factor = $matrix[$row][$i];
                foreach (range($i, 6) as $j) $matrix[$row][$j] -= $factor * $matrix[$i][$j];
            }
        }
        return ['weights' => array_column($matrix, 6), 'means' => $means, 'scales' => $scales];
    }

    private function standardize(array $features, array $means, array $scales): array
    {
        return array_map(fn ($i) => ($features[$i] - $means[$i]) / $scales[$i], range(0, 4));
    }

    private function predict(array $model, array $features): float
    {
        $x = [1.0, ...$this->standardize($features, $model['means'], $model['scales'])];
        return max(0.0, array_sum(array_map(fn ($a, $b) => $a * $b, $model['weights'], $x)));
    }
}
