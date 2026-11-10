<?php

namespace App\Services;

use App\Models\SalesTarget;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class SalesTargetService
{
    public function current(): array
    {
        $month = CarbonImmutable::now()->startOfMonth();
        $target = SalesTarget::where('month', $month->toDateString())->first();
        $totals = app(SalesAnalyticsService::class)->periods($month, CarbonImmutable::now(), true);

        return $this->progress($target, $month, (float) ($totals[$month->format('Y-m')]['net_sales'] ?? 0));
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
