<?php

namespace App\Services;

use App\Models\DeadStockMlModel;
use App\Models\DeadStockMlPrediction;
use App\Models\Product;
use App\Models\SalesItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class DeadStockMachineLearning
{
    private const FEATURES = ['units_30', 'units_previous_30', 'units_90', 'receipts_90', 'days_since_sale'];

    public function trainAndPredict(): array
    {
        $sales = SalesItem::query()->select('sales_items.product_id', 'sales_items.quantity', 'sales_items.sale_id', 'sales_transactions.sale_date')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '<=', now())
            ->orderBy('sales_transactions.sale_date')->get();
        if ($sales->isEmpty()) throw new RuntimeException('Not enough POS history. Record paid product sales before training the ML model.');

        $products = Product::query()->where('is_active', true)->get();
        $firstSale = CarbonImmutable::parse($sales->first()->sale_date)->startOfMonth();
        $lastCutoff = CarbonImmutable::now()->subDays(90)->startOfMonth();
        $samples = collect();
        for ($cutoff = $firstSale->addDays(90); $cutoff->lte($lastCutoff); $cutoff = $cutoff->addMonth()) {
            foreach ($products as $product) {
                if ($product->created_at->gt($cutoff)) continue;
                $features = $this->features($sales->where('product_id', $product->product_id), $cutoff);
                $futureUnits = $sales->where('product_id', $product->product_id)->filter(fn ($row) => CarbonImmutable::parse($row->sale_date)->gt($cutoff)
                    && CarbonImmutable::parse($row->sale_date)->lte($cutoff->addDays(90)))->sum('quantity');
                $samples->push(['cutoff' => $cutoff, 'target_end' => $cutoff->addDays(90),
                    'x' => array_values($features), 'y' => $futureUnits === 0 ? 1.0 : 0.0]);
            }
        }
        if ($samples->count() < 20 || $samples->pluck('y')->unique()->count() < 2) {
            throw new RuntimeException('ML training needs at least 20 historical product snapshots containing both stagnant and selling outcomes. Keep collecting POS history.');
        }

        [$train, $validation] = $this->validationSplit($samples);
        if ($train->count() < 20 || $validation->count() < 5 || $train->pluck('y')->unique()->count() < 2) {
            throw new RuntimeException('ML validation needs at least 20 earlier training snapshots with both outcomes and 5 later validation snapshots, separated by a 90-day outcome window. Keep collecting POS history.');
        }
        $means = []; $scales = [];
        foreach (array_keys(self::FEATURES) as $i) {
            $values = $train->pluck("x.{$i}"); $means[$i] = (float) $values->avg();
            $variance = $values->avg(fn ($v) => ($v - $means[$i]) ** 2); $scales[$i] = max(sqrt($variance), .0001);
        }
        $weights = array_fill(0, count(self::FEATURES) + 1, 0.0);
        for ($epoch = 0; $epoch < 1200; $epoch++) {
            $gradient = array_fill(0, count($weights), 0.0);
            foreach ($train as $sample) {
                $x = $this->standardize($sample['x'], $means, $scales); $prediction = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $x));
                $error = $prediction - $sample['y']; $gradient[0] += $error;
                foreach ($x as $i => $value) $gradient[$i + 1] += $error * $value;
            }
            foreach ($weights as $i => $weight) $weights[$i] -= .08 * ($gradient[$i] / $train->count() + ($i ? .001 * $weight : 0));
        }
        $correct = $validation->filter(function ($sample) use ($weights, $means, $scales) {
            $p = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $this->standardize($sample['x'], $means, $scales)));
            return ($p >= .5 ? 1.0 : 0.0) === $sample['y'];
        })->count();

        return DB::transaction(function () use ($products, $sales, $train, $validation, $correct, $weights, $means, $scales) {
            $model = DeadStockMlModel::create(['version' => 'dsml-v2-'.now()->format('YmdHis').'-'.str()->lower(str()->random(4)), 'coefficients' => $weights,
                'means' => $means, 'scales' => $scales, 'training_samples' => $train->count(),
                'validation_accuracy' => $validation->count() ? $correct / $validation->count() : null, 'trained_at' => now()]);
            foreach ($products as $product) {
                $features = $this->features($sales->where('product_id', $product->product_id), CarbonImmutable::now());
                $p = $this->sigmoid($weights[0] + $this->dot(array_slice($weights, 1), $this->standardize(array_values($features), $means, $scales)));
                $capital = (float) $product->unit_cost * $product->current_stock;
                $classification = $p >= .7 ? ($capital >= 5000 ? 'Expensive and Stagnant' : 'Dead Stock') : ($p >= .4 ? 'Slow Moving' : 'Healthy');
                DeadStockMlPrediction::updateOrCreate(['product_id' => $product->product_id], ['model_id' => $model->id,
                    'stagnation_probability' => $p, 'classification' => $classification,
                    'factors' => $this->factors($features, $capital), 'predicted_at' => now()]);
            }
            return ['model' => $model, 'predictions' => $products->count(),
                'validation_samples' => $validation->count(),
                'training_target_end' => $train->max('target_end')->toDateTimeString(),
                'validation_start' => $validation->min('cutoff')->toDateTimeString()];
        });
    }

    private function validationSplit(Collection $samples): array
    {
        $dates = $samples->pluck('cutoff')->unique(fn ($date) => $date->toDateString())->values();
        $validationStart = $dates->get((int) floor($dates->count() * .8));
        if (! $validationStart) return [collect(), collect()];
        // Keep each cutoff date wholly in one partition; purge overlapping outcomes.
        return [$samples->filter(fn ($sample) => $sample['target_end']->lte($validationStart))->values(),
            $samples->filter(fn ($sample) => $sample['cutoff']->gte($validationStart))->values()];
    }

    private function features(Collection $sales, CarbonImmutable $cutoff): array
    {
        $within = fn ($row, $from, $to) => CarbonImmutable::parse($row->sale_date)->gt($from) && CarbonImmutable::parse($row->sale_date)->lte($to);
        $last = $sales->filter(fn ($r) => CarbonImmutable::parse($r->sale_date)->lte($cutoff))->max('sale_date');
        return ['units_30' => (float) $sales->filter(fn ($r) => $within($r, $cutoff->subDays(30), $cutoff))->sum('quantity'),
            'units_previous_30' => (float) $sales->filter(fn ($r) => $within($r, $cutoff->subDays(60), $cutoff->subDays(30)))->sum('quantity'),
            'units_90' => (float) $sales->filter(fn ($r) => $within($r, $cutoff->subDays(90), $cutoff))->sum('quantity'),
            'receipts_90' => (float) $sales->filter(fn ($r) => $within($r, $cutoff->subDays(90), $cutoff))->pluck('sale_id')->unique()->count(),
            'days_since_sale' => $last ? min(365, CarbonImmutable::parse($last)->diffInDays($cutoff)) : 365.0];
    }
    private function factors(array $f, float $capital): array { return array_values(array_filter([
        $f['units_30'] == 0 ? 'No units sold in the last 30 days' : null,
        $f['units_30'] < $f['units_previous_30'] ? 'Recent demand is falling' : null,
        $f['days_since_sale'] >= 60 ? 'Last sale was at least 60 days ago' : null,
        $capital >= 5000 ? 'At least ₱5,000 is tied up in current stock' : null,
    ])); }
    private function standardize(array $x, array $means, array $scales): array { return array_map(fn ($v, $i) => ($v - $means[$i]) / $scales[$i], $x, array_keys($x)); }
    private function dot(array $a, array $b): float { return array_sum(array_map(fn ($x, $y) => $x * $y, $a, $b)); }
    private function sigmoid(float $z): float { return 1 / (1 + exp(-max(-35, min(35, $z)))); }
}
