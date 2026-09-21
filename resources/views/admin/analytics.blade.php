@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock', '/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/analytics.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/analytics.js'])
</head>
<body>
<div class="dashboard-shell analytics-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 3 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main analytics-main">
        <header class="analytics-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">CONNECTED TO POS</p>
                <h1>Sales &amp; Analytics Dashboard</h1>
                <p>Highest and lowest stock, sales, demand, best sellers, and selectable historical sales periods.</p>
            </div>
            <div class="header-tools">
                <span class="period-select">Live POS Data</span>
                <div class="export-menu" data-export-menu>
                    <button class="more-button" type="button" data-export-toggle aria-label="Export analytics data" aria-haspopup="menu" aria-expanded="false">&#8226;&#8226;&#8226;</button>
                    <div class="export-options" data-export-options role="menu" hidden>
                        <div class="export-options-heading"><span>EXPORT DATA</span><small>{{ $chartPeriodLabel }} · {{ $chartRangeLabel }} and whole analytics report</small></div>
                        <a href="{{ route('admin.analytics.export', ['period' => $salesPeriod, 'range' => $periodRanges[$salesPeriod]]) }}" role="menuitem"><span class="export-icon excel">XLS</span><span><strong>Excel workbook</strong><small>Download all analytics worksheets</small></span></a>
                    </div>
                </div>
            </div>
        </header>

        <section class="stat-grid analytics-stats" aria-label="Analytics summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL SALES</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['total_sales'], 2) }}</strong><small>From completed POS sales</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TRANSACTIONS</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['transactions']) }}</strong><small>Paid receipts recorded</small></article>
            <article class="stat-card red"><div class="stat-head"><span>AVG. ORDER VALUE</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['average_order_value'], 2) }}</strong><small>Average POS checkout</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>GROSS PROFIT</span><span class="trend-dot"></span></div><strong>{{ $summary['gross_profit'] < 0 ? '-₱' : '₱' }}{{ number_format(abs($summary['gross_profit']), 0) }}</strong><small>Sale price minus cost for units sold</small></article>
        </section>

        <section class="analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">BEST SELLERS</span><h2>Top POS Items</h2></div>
                    <button type="button">&#8226;&#8226;&#8226;</button>
                </div>
                <div class="sales-list">
                    @forelse($bestSellers as $item)
                        <div class="sales-item"><strong>{{ $item->name }}</strong><span>{{ number_format($item->units_sold) }} sold · ₱{{ number_format($item->sales_total, 2) }}</span></div>
                    @empty
                        <div class="empty-analytics">No POS sales yet. Complete a checkout to show best sellers.</div>
                    @endforelse
                </div>
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">{{ strtoupper($chartPeriodLabel) }} VIEW · {{ strtoupper($chartRangeLabel) }}</span><h2>{{ $salesPeriod === 'year' ? 'Sales Month by Month' : 'Sales Day by Day' }}</h2></div>
                    <div class="chart-period-menu" data-chart-period-menu>
                        <button class="chart-period-toggle" type="button" data-chart-period-toggle aria-label="Choose sales chart period" aria-haspopup="menu" aria-expanded="false">&#8226;&#8226;&#8226;</button>
                        <div class="chart-period-options" data-chart-period-options role="menu" hidden>
                            <a href="{{ route('admin.analytics', ['period' => 'week', 'range' => $periodRanges['week']]) }}" role="menuitem" class="{{ $salesPeriod === 'week' ? 'is-active' : '' }}" @if($salesPeriod === 'week') aria-current="page" @endif><strong>Weekly view</strong><small>Choose a week · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['period' => 'month', 'range' => $periodRanges['month']]) }}" role="menuitem" class="{{ $salesPeriod === 'month' ? 'is-active' : '' }}" @if($salesPeriod === 'month') aria-current="page" @endif><strong>Monthly view</strong><small>Choose a month · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['period' => 'year', 'range' => $periodRanges['year']]) }}" role="menuitem" class="{{ $salesPeriod === 'year' ? 'is-active' : '' }}" @if($salesPeriod === 'year') aria-current="page" @endif><strong>Yearly view</strong><small>Choose a year · month by month</small></a>
                        </div>
                    </div>
                </div>
                <form class="chart-range-filter" method="GET" action="{{ route('admin.analytics') }}">
                    <input type="hidden" name="period" value="{{ $salesPeriod }}">
                    <label for="chart-range">Specific {{ $salesPeriod }}
                        <select id="chart-range" name="range" data-chart-range>
                            @foreach($chartRangeOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($option['value'] === $periodRanges[$salesPeriod])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit">View transactions</button>
                </form>
                <div class="chart-insight" data-chart-insight aria-live="polite">
                    <span>Select a day</span><strong>View its paid sales total</strong>
                </div>
                <div class="day-chart period-{{ $salesPeriod }}" data-day-chart role="group" aria-label="Interactive {{ strtolower($chartPeriodLabel) }} bar chart of paid sales" style="--chart-columns: {{ $weeklySales->count() }}">
                    @foreach($weeklySales as $index => $day)
                        @php($showAxisLabel = $salesPeriod !== 'month' || $index === 0 || ($index + 1) % 5 === 0 || $index + 1 === $weeklySales->count())
                        <button
                            class="day-column {{ $showAxisLabel ? 'show-axis-label' : '' }}"
                            type="button"
                            data-day-bar
                            data-day="{{ $day['label'] }} · {{ $day['date'] }}"
                            data-total="{{ $day['total'] }}"
                            style="--bar-height: {{ $day['total'] > 0 ? max(8, $day['percent']) : 2 }}%; --bar-index: {{ $index }}"
                            aria-label="{{ $day['label'] }}, {{ $day['date'] }}: ₱{{ number_format($day['total'], 2) }} in paid sales"
                        >
                            <strong class="day-value">₱{{ number_format($day['total'], 2) }}</strong>
                            <span class="day-bar-track"><i></i></span>
                            <span class="day-label">{{ $day['label'] }}</span>
                            <small>{{ $day['date'] }}</small>
                        </button>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="panel analytics-panel velocity-panel">
            <div class="section-heading velocity-heading">
                <div><span class="section-kicker">SELLING SPEED</span><h2>Fast-Moving Item Ranking</h2><small>Ranked by purchase frequency (60%) and units bought (40%).</small></div>
                <span class="period">Last 30 days · paid receipts only</span>
            </div>
            <div class="velocity-table-wrap">
                <table class="velocity-table">
                    <thead>
                        <tr><th>Rank</th><th>Product</th><th>Purchase frequency</th><th>Units bought</th><th>Buying amount</th><th>Selling pace</th><th>Speed score</th></tr>
                    </thead>
                    <tbody>
                    @forelse($sellingSpeedRanking as $index => $item)
                        <tr>
                            <td><span class="rank-badge rank-{{ $index + 1 }}">#{{ $index + 1 }}</span></td>
                            <td><strong>{{ $item->name }}</strong><small>{{ $item->sku }} · {{ $item->category ?: 'Uncategorized' }}</small></td>
                            <td><strong>{{ number_format($item->purchase_frequency) }}</strong><small>{{ Str::plural('paid receipt', $item->purchase_frequency) }}</small></td>
                            <td><strong>{{ number_format($item->units_sold) }}</strong><small>{{ number_format($item->units_per_purchase, 1) }} per purchase</small></td>
                            <td><strong>₱{{ number_format($item->sales_total, 2) }}</strong><small>sales value</small></td>
                            <td><strong>{{ number_format($item->units_per_day, 2) }}</strong><small>units per day</small></td>
                            <td>
                                <div class="speed-score"><span><strong>{{ $item->speed_score }}</strong>/100</span><i><b style="width: {{ $item->speed_score }}%"></b></i></div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7"><div class="empty-analytics">No paid product sales in the last 30 days. Rankings will appear after POS checkouts.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="analytics-grid inventory-analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">STOCK LEVELS</span><h2>Highest Stock</h2></div></div>
                <div class="sales-list compact-list">
                    @forelse($highestStock as $product)
                        <div class="sales-item"><strong>{{ $product->name }}</strong><span>{{ number_format($product->current_stock) }} units</span></div>
                    @empty
                        <div class="empty-analytics">No products available.</div>
                    @endforelse
                </div>
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">STOCK LEVELS</span><h2>Lowest Stock</h2></div></div>
                <div class="sales-list compact-list">
                    @forelse($lowestStock as $product)
                        <div class="sales-item"><strong>{{ $product->name }}</strong><span>{{ number_format($product->current_stock) }} units · {{ ucfirst($product->stock_status) }}</span></div>
                    @empty
                        <div class="empty-analytics">No products available.</div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">DEMAND</span><h2>Most Requested Items</h2></div><span class="period">Last 30 days</span></div>
                <div class="sales-list">
                    @forelse($demand as $item)
                        <div class="sales-item"><strong>{{ $item->name }}</strong><span>{{ number_format($item->demand_units) }} units demanded</span></div>
                    @empty
                        <div class="empty-analytics">No demand yet because no POS sales are saved.</div>
                    @endforelse
                </div>
            </article>

            <section class="panel flow-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">INVENTORY MOVEMENT</span><h2>Stock Flow Summary</h2></div>
                    <span class="period">Live snapshot</span>
                </div>
                <div class="flow-grid">
                    <article class="flow-card green"><small>Added to Stock</small><strong>{{ number_format($stockFlow['added']) }} ITEMS</strong></article>
                    <article class="flow-card blue"><small>Sold</small><strong>{{ number_format($stockFlow['sold']) }} ITEMS</strong></article>
                    <article class="flow-card orange"><small>Manual Stock Out</small><strong>{{ number_format($stockFlow['stock_out']) }} ITEMS</strong></article>
                    <article class="flow-card violet"><small>Current Stock</small><strong>{{ number_format($stockFlow['current']) }} ITEMS</strong></article>
                </div>
            </section>
        </section>
    </main>
</div>
</body>
</html>
