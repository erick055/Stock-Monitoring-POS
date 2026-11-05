<?php

namespace App\Http\Controllers;

use App\Services\SalesAnalyticsService;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Services\ProductDemandMachineLearning;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Illuminate\View\View;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnalyticsController extends Controller
{
    public function index(Request $request): View
    {
        $data = $this->analyticsData($request);
        $perPage = in_array((int) $request->query('forecast_rows', 20), [20, 50, 100], true)
            ? (int) $request->query('forecast_rows', 20) : 20;
        $items = collect($data['aiDemandForecast']['items']);
        $page = \Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage('forecast_page');
        $data['forecastItems'] = (new \Illuminate\Pagination\LengthAwarePaginator(
            $items->forPage($page, $perPage)->values(), $items->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query(), 'pageName' => 'forecast_page']
        ))->fragment('future-demand');
        $data['speedRows'] = \App\Support\TablePagination::collection(collect($data['sellingSpeedRanking']), $request, 'speed', 'selling-speed');
        $data['slowRows'] = \App\Support\TablePagination::collection(collect($data['slowMovingProducts']), $request, 'slow', 'slow-products');
        $data['refundRows'] = \App\Support\TablePagination::collection(collect($data['dailyRefunds']), $request, 'refund', 'daily-refunds');

        return view('admin.analytics', $data);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $data = $this->analyticsData($request);
        $data['generatedAt'] = now();
        $data['generatedBy'] = $request->user()->name;
        $filename = 'motosync-analytics-'.Str::slug($data['chartPeriodLabel'].'-'.$data['chartRangeLabel']).'-'.now()->format('Y-m-d-His');

        return $this->spreadsheetExport($data, $filename);
    }

    private function analyticsData(Request $request): array
    {
        $salesPeriod = in_array($request->query('period'), ['week', 'month', 'year', 'day'], true)
            ? $request->query('period')
            : 'week';
        $now = CarbonImmutable::now();
        $movement = SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->where('sales_transactions.sale_date', '<=', $now)
            ->select('sales_items.product_id')
            ->selectRaw('SUM(CASE WHEN sales_transactions.sale_date >= ? THEN sales_items.quantity ELSE 0 END) as monthly_units', [$now->subDays(30)])
            ->selectRaw('SUM(CASE WHEN sales_transactions.sale_date >= ? THEN sales_items.quantity ELSE 0 END) as quarter_units', [$now->subDays(90)])
            ->selectRaw('MAX(sales_transactions.sale_date) as latest_sale')
            ->groupBy('sales_items.product_id')->get()->keyBy('product_id');
        $slowMovingProducts = Product::query()->where('is_active', true)->where('current_stock', '>', 0)
            ->whereNull('dead_stock_archived_at')
            ->with(['activePromotion.administrator', 'activePromotion.bundleProduct', 'deadStockArchivedBy'])
            ->get()->map(function (Product $product) use ($movement) {
                $sales = $movement->get($product->product_id);
                return app(DeadStockController::class)->scoreProduct($product,
                    (int) ($sales?->monthly_units ?? 0), (int) ($sales?->quarter_units ?? 0), $sales?->latest_sale);
            })->where('classification', 'Slow Moving')->sortByDesc('total_cost_raw')->values();
        $chartAnchor = $this->chartAnchor($salesPeriod, (string) $request->query('range', $salesPeriod === 'day' ? $request->query('transaction_date', '') : ''), $now);

        $summary = app(SalesAnalyticsService::class)->summary();

        $bestSellers = SalesItem::query()
            ->select('products.product_id', 'products.name', 'products.sku')
            ->selectRaw('SUM(sales_items.quantity) as units_sold')
            ->selectRaw('SUM(sales_items.line_total) as sales_total')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->groupBy('products.product_id', 'products.name', 'products.sku')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();

        $demand = SalesItem::query()
            ->select('products.product_id', 'products.name', 'products.category')
            ->selectRaw('SUM(sales_items.quantity) as demand_units')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [now()->subDays(30), now()])
            ->groupBy('products.product_id', 'products.name', 'products.category')
            ->orderByDesc('demand_units')
            ->limit(5)
            ->get();

        $sellingSpeedRanking = app(\App\Services\SellingSpeedService::class)->ranking()->values();

        $highestStock = Product::query()->where('is_active', true)->orderByDesc('current_stock')->limit(5)->get();
        $lowestStock = Product::query()->where('is_active', true)->orderBy('current_stock')->limit(5)->get();

        [$chartStart, $chartEnd, $chartOffsets, $chartPeriodLabel] = match ($salesPeriod) {
            'day' => [$chartAnchor->startOfDay(), $chartAnchor->endOfDay(), [0], 'Specific Date'],
            'month' => [$chartAnchor->startOfMonth(), $chartAnchor->endOfMonth(), range(0, $chartAnchor->daysInMonth - 1), 'Monthly'],
            'year' => [$chartAnchor->startOfYear(), $chartAnchor->endOfYear(), range(0, 11), 'Yearly'],
            default => [$chartAnchor->startOfWeek(), $chartAnchor->endOfWeek(), range(0, 6), 'Weekly'],
        };
        $chartRangeLabel = match ($salesPeriod) {
            'day' => $chartStart->format('F j, Y'),
            'month' => $chartStart->format('F Y'),
            'year' => $chartStart->format('Y'),
            default => $chartStart->format('M d, Y').' – '.$chartEnd->format('M d, Y'),
        };
        $chartRangeOptions = $this->chartRangeOptions($salesPeriod, $chartAnchor, $now);
        $periodRanges = [
            'day' => $chartAnchor->toDateString(),
            'week' => $chartAnchor->startOfWeek()->format('Y-m-d'),
            'month' => $chartAnchor->format('Y-m'),
            'year' => $chartAnchor->format('Y'),
        ];

        $chartTotals = app(SalesAnalyticsService::class)->periods($chartStart, $chartEnd, $salesPeriod === 'year');
        $weeklySales = collect($chartOffsets)->map(function (int $offset) use ($salesPeriod, $chartStart, $chartTotals) {
            $date = $salesPeriod === 'year' ? $chartStart->addMonths($offset) : $chartStart->addDays($offset);
            $key = $salesPeriod === 'year' ? $date->format('Y-m') : $date->toDateString();
            $totals = $chartTotals[$key] ?? ['gross_sales' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0];
            return [
                'label' => match ($salesPeriod) {
                    'month' => $date->format('j'), 'year' => $date->format('M'), default => $date->format('D'),
                },
                'date' => $salesPeriod === 'year' ? $date->format('F Y') : $date->format('M d'),
                'total' => $totals['net_sales'], 'gross_sales' => $totals['gross_sales'], 'refunds' => $totals['refunds'],
            ];
        });
        $maxChartSales = max((float) $weeklySales->max(fn ($day) => abs($day['total'])), 1);
        $weeklySales = $weeklySales->map(fn ($day) => [...$day, 'percent' => round(abs($day['total']) / $maxChartSales * 100)]);

        $stockFlow = [
            'added' => InventoryLedger::query()->sum('qty_in'),
            'sold' => SalesItem::query()->sum('quantity'),
            'stock_out' => InventoryLedger::query()->where('reason_code', '!=', 'POS_SALE')->sum('qty_out'),
            'current' => Product::query()->where('is_active', true)->sum('current_stock'),
        ];
        $aiDemandForecast = app(ProductDemandMachineLearning::class)->current();

        $dateFilters = $request->validate([
            'transaction_date' => ['nullable', 'date_format:Y-m-d'],
            'calendar_month' => ['nullable', 'date_format:Y-m'],
        ]);
        $selectedTransactionDate = $salesPeriod === 'day' ? $chartAnchor->startOfDay() : CarbonImmutable::parse($dateFilters['transaction_date'] ?? $now->toDateString())->startOfDay();
        $dailySales = $this->dailyPaidSales($selectedTransactionDate);
        $dailySummary = app(SalesAnalyticsService::class)->summary($selectedTransactionDate, $selectedTransactionDate->endOfDay());
        $dailySalesTotal = $dailySummary['net_sales'];
        $dailyRefunds = app(SalesAnalyticsService::class)->approvedReturns($selectedTransactionDate, $selectedTransactionDate->endOfDay())->with('product')->orderBy('approved_at')->get();
        $dayTransactions = $dailySales->with('staff')->withSum('items as units_count', 'quantity')
            ->latest('sale_date')->orderByDesc('sale_id')->paginate(\App\Support\TablePagination::size($request, 'transactions_rows'), ['*'], 'transactions_page')
            ->withQueryString()->fragment('transaction-history');

        return compact(
            'slowMovingProducts',
            'summary',
            'bestSellers',
            'demand',
            'aiDemandForecast',
            'sellingSpeedRanking',
            'highestStock',
            'lowestStock',
            'weeklySales',
            'salesPeriod',
            'chartPeriodLabel',
            'chartRangeLabel',
            'chartRangeOptions',
            'periodRanges',
            'stockFlow', 'selectedTransactionDate', 'dayTransactions', 'dailySalesTotal', 'dailySummary', 'dailyRefunds'
        );
    }

    private function dailyPaidSales(CarbonImmutable $date): Builder
    {
        return SalesTransaction::query()->where('payment_status', 'paid')
            ->where('sale_date', '>=', $date->startOfDay())->where('sale_date', '<', $date->startOfDay()->addDay());
    }

    private function chartAnchor(string $period, string $range, CarbonImmutable $fallback): CarbonImmutable
    {
        $parts = array_map('intval', explode('-', $range));

        if (in_array($period, ['week', 'day'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range)
            && checkdate($parts[1], $parts[2], $parts[0])) {
            return CarbonImmutable::create($parts[0], $parts[1], $parts[2], 0, 0, 0);
        }

        if ($period === 'month' && preg_match('/^\d{4}-\d{2}$/', $range)
            && isset($parts[1]) && checkdate($parts[1], 1, $parts[0])) {
            return CarbonImmutable::create($parts[0], $parts[1], 1, 0, 0, 0);
        }

        if ($period === 'year' && preg_match('/^\d{4}$/', $range)
            && $parts[0] >= 1900 && $parts[0] <= 2200) {
            return CarbonImmutable::create($parts[0], 1, 1, 0, 0, 0);
        }

        return $fallback;
    }

    private function chartRangeOptions(string $period, CarbonImmutable $selected, CarbonImmutable $now)
    {
        $anchors = SalesTransaction::query()
            ->where('payment_status', 'paid')
            ->selectRaw('DATE(sale_date) as sale_day')
            ->distinct()
            ->pluck('sale_day')
            ->filter()
            ->map(fn (string $date) => $this->periodAnchor($period, CarbonImmutable::parse($date)));

        $refundAnchors = app(SalesAnalyticsService::class)->approvedReturns()->whereNotNull('approved_at')
            ->selectRaw('DATE(approved_at) as approval_day')->distinct()->pluck('approval_day')
            ->map(fn (string $date) => $this->periodAnchor($period, CarbonImmutable::parse($date)));

        return $anchors->concat($refundAnchors)
            ->push($this->periodAnchor($period, $now))
            ->push($this->periodAnchor($period, $selected))
            ->unique(fn (CarbonImmutable $date) => $this->periodValue($period, $date))
            ->sortByDesc(fn (CarbonImmutable $date) => $date->timestamp)
            ->values()
            ->map(fn (CarbonImmutable $date) => [
                'value' => $this->periodValue($period, $date),
                'label' => match ($period) {
                    'day' => $date->format('F j, Y'),
                    'month' => $date->format('F Y'),
                    'year' => $date->format('Y'),
                    default => $date->format('M d').' – '.$date->endOfWeek()->format('M d, Y'),
                },
            ]);
    }

    private function periodAnchor(string $period, CarbonImmutable $date): CarbonImmutable
    {
        return match ($period) {
            'day' => $date->startOfDay(),
            'month' => $date->startOfMonth(),
            'year' => $date->startOfYear(),
            default => $date->startOfWeek(),
        };
    }

    private function periodValue(string $period, CarbonImmutable $date): string
    {
        return match ($period) {
            'month' => $date->format('Y-m'),
            'year' => $date->format('Y'),
            default => $date->format('Y-m-d'),
        };
    }

    private function spreadsheetExport(array $data, string $filename): BinaryFileResponse
    {
        $path = tempnam(storage_path('app'), 'analytics_');
        $writer = new XlsxWriter;
        $writer->setCreator('MotoSync');
        $writer->openToFile($path);

        $titleStyle = (new Style)->setFontBold()->setFontSize(14)->setFontColor(Color::WHITE)->setBackgroundColor('4F2780');
        $headerStyle = (new Style)->setFontBold()->setFontColor(Color::WHITE)->setBackgroundColor('6D3AA5');
        $currencyStyle = (new Style)->setFormat('"PHP "#,##0.00');
        $decimalStyle = (new Style)->setFormat('#,##0.00');
        $oneDecimalStyle = (new Style)->setFormat('#,##0.0');

        $overview = $writer->getCurrentSheet();
        $overview->setName('Overview');
        $overview->setColumnWidth(30, 1);
        $overview->setColumnWidth(24, 2);
        $writer->addRow(Row::fromValues(['MotoSync Analytics Report'], $titleStyle));
        $writer->addRow(Row::fromValues(['Selected view', $data['chartPeriodLabel'].' · '.$data['chartRangeLabel']]));
        $writer->addRow(Row::fromValues(['Transaction date', $data['selectedTransactionDate']->format('F d, Y')]));
        $writer->addRow(Row::fromValues(['Generated at', $data['generatedAt']->format('Y-m-d H:i:s')]));
        $writer->addRow(Row::fromValues(['Generated by', $data['generatedBy']]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Metric', 'Value'], $headerStyle));
        foreach ([
            ['Gross sales', (float) $data['summary']['gross_sales'], true],
            ['Refunds', (float) $data['summary']['refunds'], true],
            ['Net sales', (float) $data['summary']['net_sales'], true],
            ['Paid transactions', (int) $data['summary']['transactions'], false],
            ['Average order value', (float) $data['summary']['average_order_value'], true],
            ['Gross profit', (float) $data['summary']['gross_profit'], true],
            ['Stock added', (int) $data['stockFlow']['added'], false],
            ['Units sold', (int) $data['stockFlow']['sold'], false],
            ['Manual stock out', (int) $data['stockFlow']['stock_out'], false],
            ['Current stock', (int) $data['stockFlow']['current'], false],
        ] as $row) {
            $writer->addRow(Row::fromValuesWithStyles([$row[0], $row[1]], null, $row[2] ? [1 => $currencyStyle] : []));
        }

        $this->addSheet($writer, 'Period Sales', ['Period', 'Date', 'Gross sales', 'Refunds', 'Net sales'], $data['weeklySales']->map(fn ($day) => [
            $day['label'], $day['date'], (float) $day['gross_sales'], (float) $day['refunds'], (float) $day['total'],
        ])->all(), $headerStyle, [12, 16, 18, 18, 18], [2 => $currencyStyle, 3 => $currencyStyle, 4 => $currencyStyle]);

        $this->addSheet($writer, 'Selling Speed', ['Rank', 'SKU', 'Product', 'Category', 'Paid receipts', 'Units sold', 'Units per purchase', 'Sales value', 'Sales velocity (units/day)'], $data['sellingSpeedRanking']->map(fn ($item, $index) => [
            $index + 1, $item->sku, $item->name, $item->category ?: 'Uncategorized', (int) $item->purchase_frequency,
            (int) $item->units_sold, (float) $item->units_per_purchase, (float) $item->sales_total,
            (float) $item->units_per_day,
        ])->all(), $headerStyle, [8, 17, 25, 18, 15, 14, 19, 17, 26], [6 => $oneDecimalStyle, 7 => $currencyStyle, 8 => $decimalStyle]);

        $this->addSheet($writer, 'Best Sellers', ['SKU', 'Product', 'Units sold', 'Sales value'], $data['bestSellers']->map(fn ($item) => [
            $item->sku, $item->name, (int) $item->units_sold, (float) $item->sales_total,
        ])->all(), $headerStyle, [17, 25, 14, 17], [3 => $currencyStyle]);

        $this->addSheet($writer, 'Demand', ['Product', 'Category', 'Demand units'], $data['demand']->map(fn ($item) => [
            $item->name, $item->category ?: 'Uncategorized', (int) $item->demand_units,
        ])->all(), $headerStyle, [25, 18, 15]);

        $this->addSheet($writer, 'Future Demand', ['SKU', 'Product', 'Stock', 'Sold past 30 days', 'Estimated next 30 days', 'Outlook'], array_map(fn ($item) => [
            $item['sku'], $item['name'], $item['stock'], $item['recent_units'], $item['predicted_units'], $item['trend'],
        ], $data['aiDemandForecast']['items']), $headerStyle, [17, 25, 12, 20, 24, 25]);

        $inventoryRows = $data['highestStock']->map(fn ($product) => [
            'Highest stock', $product->sku, $product->name, (int) $product->current_stock, (int) $product->reorder_level, ucfirst($product->stock_status),
        ])->concat($data['lowestStock']->map(fn ($product) => [
            'Lowest stock', $product->sku, $product->name, (int) $product->current_stock, (int) $product->reorder_level, ucfirst($product->stock_status),
        ]))->all();
        $this->addSheet($writer, 'Inventory', ['List', 'SKU', 'Product', 'Current stock', 'Reorder level', 'Status'], $inventoryRows, $headerStyle, [17, 18, 26, 16, 16, 14]);

        $dailySheet = $writer->addNewSheetAndMakeItCurrent();
        $dailySheet->setName('Daily Transactions');
        $writer->addRow(Row::fromValues(['Receipt', 'Date and time', 'Cashier', 'Payment method', 'Paid total'], $headerStyle));
        foreach ($this->dailyPaidSales($data['selectedTransactionDate'])->with('staff')->orderBy('sale_id')->lazyById(250, 'sale_id') as $sale) {
            $writer->addRow(Row::fromValuesWithStyles([
                'POS-'.str_pad((string) $sale->sale_id, 6, '0', STR_PAD_LEFT), $sale->sale_date->format('Y-m-d H:i:s'),
                $sale->staff?->name ?? 'Former staff', $sale->payment_method ?? 'cash', (float) $sale->total_sale_amount,
            ], null, [4 => $currencyStyle]));
        }
        $this->addSheet($writer, 'Daily Summary', ['Metric', 'Value'], collect([
            'gross_sales' => 'Gross sales', 'refunds' => 'Refunds', 'net_sales' => 'Net sales',
            'average_order_value' => 'Average order value', 'gross_profit' => 'Gross profit',
        ])->map(fn ($label, $key) => [$label, $data['dailySummary'][$key]])->values()->all(), $headerStyle, [24, 20], [1 => $currencyStyle]);
        $this->addSheet($writer, 'Approved Refunds', ['Return', 'Receipt', 'Approval date', 'Product', 'Quantity', 'Refund'], $data['dailyRefunds']->map(fn ($return) => [
            $return->return_id, 'POS-'.str_pad((string) $return->sale_id, 6, '0', STR_PAD_LEFT), $return->approved_at->format('Y-m-d H:i:s'),
            $return->product?->name ?? 'Deleted product', $return->quantity, (float) $return->refund_amount,
        ])->all(), $headerStyle, [12, 18, 24, 30, 12, 18], [5 => $currencyStyle]);
        $writer->close();

        return response()->download($path, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ])->deleteFileAfterSend(true);
    }

    private function addSheet(XlsxWriter $writer, string $name, array $headers, array $rows, Style $headerStyle, array $widths = [], array $columnStyles = []): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName($name);
        foreach ($widths as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }
        $writer->addRow(Row::fromValues($headers, $headerStyle));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValuesWithStyles($row, null, $columnStyles));
        }
    }
}
