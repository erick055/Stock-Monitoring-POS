<?php

namespace App\Services;

use App\Models\SalesItem;
use App\Models\SalesTarget;
use App\Models\SalesTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SalesTargetService
{
    public function current(): array
    {
        $now = CarbonImmutable::now();
        $month = $now->startOfMonth();
        $target = SalesTarget::where('month', $month->toDateString())->first();
        $analytics = app(SalesAnalyticsService::class);
        $totals = $analytics->periods($month, $now, true)[$month->format('Y-m')] ?? [];
        $progress = $this->progress($target, $month, (float) ($totals['net_sales'] ?? 0));
        $refunds = (float) ($totals['refunds'] ?? 0);

        return $progress + [
            'gross_sales' => (float) ($totals['gross_sales'] ?? 0),
            'refunds' => $refunds,
            'recovered' => $target ? min($progress['amount'], max(0, $progress['achieved'])) : null,
            'above_target' => $target ? max(0, round($progress['achieved'] - $progress['amount'], 2)) : null,
            'gross_sales_needed' => $target ? round($progress['amount'] + $refunds, 2) : null,
            'gross_profit' => $this->grossProfit($analytics, $month, $now, $progress['achieved']),
        ];
    }

    public function history(Request $request): \Illuminate\Pagination\LengthAwarePaginator
    {
        $rows = SalesTarget::orderByDesc('month')->paginate(
            \App\Support\TablePagination::size($request, 'target_rows'), ['*'], 'target_page'
        )->withQueryString()->fragment('sales-target-history');
        $firstMonth = $rows->getCollection()->min('month');
        $now = CarbonImmutable::now();
        $totals = $firstMonth && $firstMonth->lessThanOrEqualTo($now)
            ? app(SalesAnalyticsService::class)->periods($firstMonth, $now, true) : [];
        $rows->setCollection($rows->getCollection()->map(fn (SalesTarget $target) =>
            $this->progress($target, $target->month, (float) ($totals[$target->month->format('Y-m')]['net_sales'] ?? 0))
        ));

        return $rows;
    }

    private function grossProfit(SalesAnalyticsService $analytics, CarbonImmutable $month, CarbonImmutable $now, float $netSales): ?float
    {
        $sales = SalesTransaction::query()->where('payment_status', 'paid')->whereBetween('sale_date', [$month, $now]);
        $sellableReturns = $analytics->approvedReturns($month, $now)->where('item_condition', 'sellable');
        $originalItems = SalesItem::query()
            ->whereColumn('sales_items.sale_id', 'customer_returns.sale_id')
            ->whereColumn('sales_items.product_id', 'customer_returns.product_id');

        if ((clone $sales)->whereRaw('total_sale_amount > COALESCE(labor_amount, 0) + COALESCE(tax_amount, 0)')->whereDoesntHave('items')->exists()
            || (clone $sellableReturns)->whereNotExists((clone $originalItems)->selectRaw('1')->toBase())->exists()) {
            return null;
        }

        $tax = (float) (clone $sales)->sum('tax_amount');
        $soldCost = SalesItem::query()->whereIn('sale_id', $sales->select('sale_id'))
            ->selectRaw('COALESCE(SUM(unit_cost * quantity), 0) AS cost')->value('cost');
        // A return identifies a receipt and product, so use the original quantity-weighted cost.
        $unitCost = $originalItems->selectRaw('1.0 * SUM(unit_cost * quantity) / NULLIF(SUM(quantity), 0)');
        $restockedCost = $sellableReturns
            ->selectRaw('COALESCE(SUM(customer_returns.quantity * ('.$unitCost->toSql().')), 0) AS cost', $unitCost->getBindings())
            ->value('cost');

        return round($netSales - $tax - (float) $soldCost + (float) $restockedCost, 2);
    }

    private function progress(?SalesTarget $target, CarbonImmutable $month, float $achieved): array
    {
        $amount = $target ? (float) $target->amount : null;
        $percent = $amount ? round($achieved / $amount * 100, 1) : 0;
        $now = CarbonImmutable::now();
        $status = ! $target ? 'No target set' : ($month->isAfter($now) ? 'Not started'
            : ($achieved >= $amount ? 'Achieved' : ($month->endOfMonth()->isBefore($now) ? 'Below target' : 'In progress')));

        return ['month' => $month, 'amount' => $amount, 'achieved' => $achieved, 'percent' => $percent,
            'bar_percent' => max(0, min(100, $percent)), 'remaining' => $amount === null ? null : max(0, round($amount - $achieved, 2)),
            'status' => $status];
    }
}
