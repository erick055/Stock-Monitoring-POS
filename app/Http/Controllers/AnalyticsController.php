<?php

namespace App\Http\Controllers;

use App\Models\InventoryLedger;
use App\Models\Product;
use App\Models\SalesItem;
use App\Models\SalesTransaction;
use App\Services\GroqDemandForecaster;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return view('admin.analytics', $this->analyticsData($request));
    }

    public function generateDemandForecast(Request $request, GroqDemandForecaster $forecaster): RedirectResponse
    {
        $forecast = $forecaster->generate((int) $request->user()->id);

        return back()->with(
            $forecast['available'] ? 'success' : 'error',
            $forecast['available'] ? 'Groq AI generated a new 30-day product demand forecast.' : $forecast['message'],
        );
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
        $salesPeriod = in_array($request->query('period'), ['week', 'month', 'year'], true)
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
        $chartAnchor = $this->chartAnchor($salesPeriod, (string) $request->query('range', ''), $now);

        $paidSales = SalesTransaction::query()->where('payment_status', 'paid');
        $totalSales = (float) (clone $paidSales)->sum('total_sale_amount');
        $transactionCount = (clone $paidSales)->count();
        $averageOrderValue = $transactionCount > 0 ? $totalSales / $transactionCount : 0;

        $profit = SalesItem::query()
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->selectRaw('COALESCE(SUM((sales_items.unit_sale_price - sales_items.unit_cost) * sales_items.quantity), 0) as profit')
            ->value('profit');

        $summary = [
            'total_sales' => $totalSales,
            'transactions' => $transactionCount,
            'average_order_value' => $averageOrderValue,
            'gross_profit' => (float) $profit,
        ];

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
            ->select('products.name', 'products.category')
            ->selectRaw('SUM(sales_items.quantity) as demand_units')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [now()->subDays(30), now()])
            ->groupBy('products.name', 'products.category')
            ->orderByDesc('demand_units')
            ->limit(5)
            ->get();

        $rankingStart = CarbonImmutable::now()->subDays(29)->startOfDay();
        $rankingEnd = CarbonImmutable::now()->endOfDay();
        $rankingRows = SalesItem::query()
            ->select('products.product_id', 'products.name', 'products.sku', 'products.category')
            ->selectRaw('COUNT(DISTINCT sales_items.sale_id) as purchase_frequency')
            ->selectRaw('SUM(sales_items.quantity) as units_sold')
            ->selectRaw('SUM(sales_items.line_total) as sales_total')
            ->join('products', 'sales_items.product_id', '=', 'products.product_id')
            ->join('sales_transactions', 'sales_items.sale_id', '=', 'sales_transactions.sale_id')
            ->where('products.is_active', true)
            ->where('sales_transactions.payment_status', 'paid')
            ->whereBetween('sales_transactions.sale_date', [$rankingStart, $rankingEnd])
            ->groupBy('products.product_id', 'products.name', 'products.sku', 'products.category')
            ->get();

        $maxFrequency = max((int) $rankingRows->max('purchase_frequency'), 1);
        $maxUnits = max((int) $rankingRows->max('units_sold'), 1);
        $sellingSpeedRanking = $rankingRows
            ->map(function ($item) use ($maxFrequency, $maxUnits) {
                $frequencyScore = ((int) $item->purchase_frequency / $maxFrequency) * 60;
                $volumeScore = ((int) $item->units_sold / $maxUnits) * 40;
                $item->speed_score = round($frequencyScore + $volumeScore);
                $item->units_per_purchase = (int) $item->purchase_frequency > 0
                    ? (int) $item->units_sold / (int) $item->purchase_frequency
                    : 0;
                $item->units_per_day = (int) $item->units_sold / 30;

                return $item;
            })
            ->sort(function ($left, $right) {
                return [$right->speed_score, (int) $right->purchase_frequency, (int) $right->units_sold, (float) $right->sales_total]
                    <=> [$left->speed_score, (int) $left->purchase_frequency, (int) $left->units_sold, (float) $left->sales_total];
            })
            ->take(10)
            ->values();

        $highestStock = Product::query()->where('is_active', true)->orderByDesc('current_stock')->limit(5)->get();
        $lowestStock = Product::query()->where('is_active', true)->orderBy('current_stock')->limit(5)->get();

        [$chartStart, $chartEnd, $chartOffsets, $chartPeriodLabel] = match ($salesPeriod) {
            'month' => [$chartAnchor->startOfMonth(), $chartAnchor->endOfMonth(), range(0, $chartAnchor->daysInMonth - 1), 'Monthly'],
            'year' => [$chartAnchor->startOfYear(), $chartAnchor->endOfYear(), range(0, 11), 'Yearly'],
            default => [$chartAnchor->startOfWeek(), $chartAnchor->endOfWeek(), range(0, 6), 'Weekly'],
        };
        $chartRangeLabel = match ($salesPeriod) {
            'month' => $chartStart->format('F Y'),
            'year' => $chartStart->format('Y'),
            default => $chartStart->format('M d, Y').' – '.$chartEnd->format('M d, Y'),
        };
        $chartRangeOptions = $this->chartRangeOptions($salesPeriod, $chartAnchor, $now);
        $periodRanges = [
            'week' => $chartAnchor->startOfWeek()->format('Y-m-d'),
            'month' => $chartAnchor->format('Y-m'),
            'year' => $chartAnchor->format('Y'),
        ];

        $chartRaw = SalesTransaction::query()
            ->select(['sale_date', 'total_sale_amount'])
            ->where('payment_status', 'paid')
            ->whereBetween('sale_date', [$chartStart, $chartEnd])
            ->get()
            ->groupBy(fn (SalesTransaction $sale) => $salesPeriod === 'year'
                ? $sale->sale_date->format('Y-m')
                : $sale->sale_date->toDateString())
            ->map(fn ($sales) => (float) $sales->sum('total_sale_amount'));

        $maxChartSales = max((float) $chartRaw->max(), 1);
        $weeklySales = collect($chartOffsets)->map(function (int $offset) use ($salesPeriod, $chartStart, $chartRaw, $maxChartSales) {
            $date = $salesPeriod === 'year' ? $chartStart->addMonths($offset) : $chartStart->addDays($offset);
            $key = $salesPeriod === 'year' ? $date->format('Y-m') : $date->toDateString();
            $total = (float) ($chartRaw[$key] ?? 0);

            return [
                'label' => match ($salesPeriod) {
                    'month' => $date->format('j'),
                    'year' => $date->format('M'),
                    default => $date->format('D'),
                },
                'date' => $salesPeriod === 'year' ? $date->format('F Y') : $date->format('M d'),
                'total' => $total,
                'percent' => round(($total / $maxChartSales) * 100),
            ];
        });

        $stockFlow = [
            'added' => InventoryLedger::query()->sum('qty_in'),
            'sold' => SalesItem::query()->sum('quantity'),
            'stock_out' => InventoryLedger::query()->where('reason_code', '!=', 'POS_SALE')->sum('qty_out'),
            'current' => Product::query()->where('is_active', true)->sum('current_stock'),
        ];
        $aiDemandForecast = app(GroqDemandForecaster::class)->current();

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
            'stockFlow'
        );
    }

    private function chartAnchor(string $period, string $range, CarbonImmutable $fallback): CarbonImmutable
    {
        $parts = array_map('intval', explode('-', $range));

        if ($period === 'week' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $range)
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

        return $anchors
            ->push($this->periodAnchor($period, $now))
            ->push($this->periodAnchor($period, $selected))
            ->unique(fn (CarbonImmutable $date) => $this->periodValue($period, $date))
            ->sortByDesc(fn (CarbonImmutable $date) => $date->timestamp)
            ->values()
            ->map(fn (CarbonImmutable $date) => [
                'value' => $this->periodValue($period, $date),
                'label' => match ($period) {
                    'month' => $date->format('F Y'),
                    'year' => $date->format('Y'),
                    default => $date->format('M d').' – '.$date->endOfWeek()->format('M d, Y'),
                },
            ]);
    }

    private function periodAnchor(string $period, CarbonImmutable $date): CarbonImmutable
    {
        return match ($period) {
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
        $writer->addRow(Row::fromValues(['Generated at', $data['generatedAt']->format('Y-m-d H:i:s')]));
        $writer->addRow(Row::fromValues(['Generated by', $data['generatedBy']]));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Metric', 'Value'], $headerStyle));
        foreach ([
            ['Total sales', (float) $data['summary']['total_sales'], true],
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

        $this->addSheet($writer, 'Period Sales', ['Period', 'Date', 'Paid sales'], $data['weeklySales']->map(fn ($day) => [
            $day['label'], $day['date'], (float) $day['total'],
        ])->all(), $headerStyle, [12, 16, 18], [2 => $currencyStyle]);

        $this->addSheet($writer, 'Selling Speed', ['Rank', 'SKU', 'Product', 'Category', 'Paid receipts', 'Units bought', 'Units per purchase', 'Sales value', 'Units per day', 'Speed score'], $data['sellingSpeedRanking']->map(fn ($item, $index) => [
            $index + 1, $item->sku, $item->name, $item->category ?: 'Uncategorized', (int) $item->purchase_frequency,
            (int) $item->units_sold, (float) $item->units_per_purchase, (float) $item->sales_total,
            (float) $item->units_per_day, (int) $item->speed_score,
        ])->all(), $headerStyle, [8, 17, 25, 18, 15, 14, 19, 17, 15, 13], [6 => $oneDecimalStyle, 7 => $currencyStyle, 8 => $decimalStyle]);

        $this->addSheet($writer, 'Best Sellers', ['SKU', 'Product', 'Units sold', 'Sales value'], $data['bestSellers']->map(fn ($item) => [
            $item->sku, $item->name, (int) $item->units_sold, (float) $item->sales_total,
        ])->all(), $headerStyle, [17, 25, 14, 17], [3 => $currencyStyle]);

        $this->addSheet($writer, 'Demand', ['Product', 'Category', 'Demand units'], $data['demand']->map(fn ($item) => [
            $item->name, $item->category ?: 'Uncategorized', (int) $item->demand_units,
        ])->all(), $headerStyle, [25, 18, 15]);

        $inventoryRows = $data['highestStock']->map(fn ($product) => [
            'Highest stock', $product->sku, $product->name, (int) $product->current_stock, (int) $product->reorder_level, ucfirst($product->stock_status),
        ])->concat($data['lowestStock']->map(fn ($product) => [
            'Lowest stock', $product->sku, $product->name, (int) $product->current_stock, (int) $product->reorder_level, ucfirst($product->stock_status),
        ]))->all();
        $this->addSheet($writer, 'Inventory', ['List', 'SKU', 'Product', 'Current stock', 'Reorder level', 'Status'], $inventoryRows, $headerStyle, [17, 18, 26, 16, 16, 14]);

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
