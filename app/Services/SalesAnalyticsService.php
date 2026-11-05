<?php

namespace App\Services;

use App\Models\CustomerReturn;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class SalesAnalyticsService
{
    /** Monetary totals for a page of products, using a fixed number of queries. */
    public function productSummaries(array $productIds): \Illuminate\Support\Collection
    {
        if ($productIds === []) return collect();
        $gross = SalesItem::query()->whereIn('product_id', $productIds)
            ->whereHas('sale', fn ($sale) => $sale->where('payment_status', 'paid'))
            ->select('product_id')->selectRaw('SUM(line_total) as gross_sales, SUM((unit_sale_price - unit_cost) * quantity) as gross_profit')
            ->groupBy('product_id')->get()->keyBy('product_id');
        $refunds = $this->approvedReturns()->whereIn('customer_returns.product_id', $productIds)
            ->select('customer_returns.product_id')->selectRaw('SUM(refund_amount) as refunds')
            ->groupBy('customer_returns.product_id')->pluck('refunds', 'product_id');
        $margins = SalesItem::query()->whereIn('product_id', $productIds)->select('sale_id', 'product_id')
            ->selectRaw('1.0 * SUM((unit_sale_price - unit_cost) * quantity) / NULLIF(SUM(quantity), 0) as unit_margin')
            ->groupBy('sale_id', 'product_id');
        $reversals = $this->approvedReturns()->whereIn('customer_returns.product_id', $productIds)
            ->joinSub($margins, 'sold_margin', fn ($join) => $join
                ->on('customer_returns.sale_id', '=', 'sold_margin.sale_id')->on('customer_returns.product_id', '=', 'sold_margin.product_id'))
            ->select('customer_returns.product_id')->selectRaw('SUM(customer_returns.quantity * sold_margin.unit_margin) as reversed_margin')
            ->groupBy('customer_returns.product_id')->pluck('reversed_margin', 'product_id');

        return collect($productIds)->mapWithKeys(function ($id) use ($gross, $refunds, $reversals) {
            $sales = (float) ($gross->get($id)?->gross_sales ?? 0);
            $refund = (float) ($refunds[$id] ?? 0);
            return [$id => ['gross_sales' => round($sales, 2), 'refunds' => round($refund, 2),
                'net_sales' => round($sales - $refund, 2),
                'gross_profit' => round((float) ($gross->get($id)?->gross_profit ?? 0) - (float) ($reversals[$id] ?? 0), 2)]];
        });
    }

    public function approvedReturns(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $staffId = null, ?int $productId = null): Builder
    {
        return CustomerReturn::query()->where('customer_returns.status', 'approved')
            ->whereHas('sale', fn ($sale) => $sale->where('payment_status', 'paid')->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId)))
            ->when($from, fn ($q) => $q->where('customer_returns.approved_at', '>=', $from))
            ->when($to, fn ($q) => $q->where('customer_returns.approved_at', '<=', $to))
            ->when($productId !== null, fn ($q) => $q->where('customer_returns.product_id', $productId));
    }

    /** Gross sales and refunds are grouped independently by their accounting dates. */
    public function periods(CarbonInterface $from, CarbonInterface $to, bool $monthly = false, ?int $staffId = null): array
    {
        $saleBucket = $monthly ? 'SUBSTR(sale_date, 1, 7)' : 'DATE(sale_date)';
        $refundBucket = $monthly ? 'SUBSTR(approved_at, 1, 7)' : 'DATE(approved_at)';
        $gross = SalesTransaction::query()->where('payment_status', 'paid')
            ->whereBetween('sale_date', [$from, $to])
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId))
            ->selectRaw($saleBucket.' as bucket, SUM(total_sale_amount) as amount')->groupByRaw($saleBucket)->pluck('amount', 'bucket');
        $refunds = $this->approvedReturns($from, $to, $staffId)
            ->selectRaw($refundBucket.' as bucket, SUM(refund_amount) as amount')->groupByRaw($refundBucket)->pluck('amount', 'bucket');

        return $gross->keys()->merge($refunds->keys())->unique()->mapWithKeys(function ($key) use ($gross, $refunds) {
            $grossAmount = round((float) ($gross[$key] ?? 0), 2);
            $refundAmount = round((float) ($refunds[$key] ?? 0), 2);

            return [$key => ['gross_sales' => $grossAmount, 'refunds' => $refundAmount, 'net_sales' => round($grossAmount - $refundAmount, 2)]];
        })->all();
    }

    public function summary(?CarbonInterface $from = null, ?CarbonInterface $to = null, ?int $staffId = null, ?int $productId = null): array
    {
        $sales = SalesTransaction::query()->where('payment_status', 'paid')
            ->when($from, fn ($q) => $q->where('sale_date', '>=', $from))
            ->when($to, fn ($q) => $q->where('sale_date', '<=', $to))
            ->when($staffId !== null, fn ($q) => $q->where('staff_id', $staffId));
        $items = SalesItem::query()->whereIn('sale_id', (clone $sales)->select('sale_id'))
            ->when($productId !== null, fn ($q) => $q->where('product_id', $productId));
        $count = $productId === null ? (clone $sales)->count() : (clone $items)->distinct()->count('sale_id');
        $gross = $productId === null ? (float) $sales->sum('total_sale_amount') : (float) (clone $items)->sum('line_total');
        $profit = (float) (clone $items)->selectRaw('COALESCE(SUM((unit_sale_price - unit_cost) * quantity), 0) as margin')->value('margin');
        $returns = $this->approvedReturns($from, $to, $staffId, $productId);
        $refunds = (float) (clone $returns)->sum('refund_amount');
        // Returns identify a receipt/product, not an individual sale line. Aggregate first
        // so duplicate lines cannot multiply refunds; use their quantity-weighted margin.
        $margins = SalesItem::query()->select('sale_id', 'product_id')
            ->selectRaw('1.0 * SUM((unit_sale_price - unit_cost) * quantity) / NULLIF(SUM(quantity), 0) as unit_margin')
            ->groupBy('sale_id', 'product_id');
        $reversedProfit = (float) (clone $returns)->joinSub($margins, 'sold_margin', fn ($join) => $join
            ->on('customer_returns.sale_id', '=', 'sold_margin.sale_id')->on('customer_returns.product_id', '=', 'sold_margin.product_id'))
            ->selectRaw('COALESCE(SUM(customer_returns.quantity * sold_margin.unit_margin), 0) as reversed_margin')->value('reversed_margin');
        $net = round($gross - $refunds, 2);

        return [
            'gross_sales' => round($gross, 2), 'refunds' => round($refunds, 2), 'net_sales' => $net,
            'total_sales' => $net, 'transactions' => $count,
            'average_order_value' => $count > 0 ? $net / $count : 0,
            'gross_profit' => round($profit - $reversedProfit, 2),
        ];
    }
}
