@php
$navigation = [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock', '/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analytics | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/analytics.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/analytics.js'])
    @include('partials.appearance')
</head>
<body>
<div class="dashboard-shell analytics-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 4 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main analytics-main">
        <header class="analytics-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div>
                <p class="welcome">CONNECTED TO POS</p>
                <h1>Sales &amp; Analytics Dashboard</h1>
                <p>Highest and lowest stock, sales, demand, best sellers, and selectable historical sales periods.</p>
            </div>
            <div class="header-tools">
                <span class="period-select">Live POS Data</span>
                <div class="export-menu" data-export-menu>
                    <button class="more-button" type="button" data-export-toggle aria-label="Export analytics data" aria-haspopup="menu" aria-expanded="false"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                    <div class="export-options" data-export-options role="menu" hidden>
                        <div class="export-options-heading"><span>EXPORT DATA</span><small>{{ $chartPeriodLabel }} · {{ $chartRangeLabel }} and whole analytics report</small></div>
                        <a href="{{ route('admin.analytics.export', ['period' => $salesPeriod, 'range' => $periodRanges[$salesPeriod], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem"><span class="export-icon excel">XLS</span><span><strong>Excel workbook</strong><small>Download all analytics worksheets</small></span></a>
                    </div>
                </div>
            </div>
        </header>

        @if(session('success'))<div class="analytics-message success" role="status">{{ session('success') }}</div>@endif
        @if(session('error'))<div class="analytics-message error" role="alert">{{ session('error') }}</div>@endif
        @if($errors->any())<div class="analytics-message error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="stat-grid analytics-stats" aria-label="Analytics summary">
            <article class="stat-card purple"><div class="stat-head"><span>NET SALES</span><span class="trend-dot"></span></div><strong>{{ \App\Support\Display::money($summary['total_sales']) }}</strong><small>Gross sales: {{ \App\Support\Display::money($summary['gross_sales']) }}</small><small>Refunds: {{ \App\Support\Display::money($summary['refunds']) }}</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TRANSACTIONS</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['transactions']) }}</strong><small>Paid receipts recorded</small></article>
            <article class="stat-card red"><div class="stat-head"><span>AVG. ORDER VALUE</span><span class="trend-dot"></span></div><strong>{{ \App\Support\Display::money($summary['average_order_value']) }}</strong><small>Average POS checkout</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>GROSS PROFIT</span><span class="trend-dot"></span></div><strong>{{ \App\Support\Display::money($summary['gross_profit']) }}</strong><small>Sale margins minus approved returned-unit margins</small></article>
        </section>

        <section class="analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">BEST SELLERS</span><h2>Top POS Items</h2></div>
                    <button type="button"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                </div>
                <div class="sales-list">
                    @forelse($bestSellers as $item)
                        <div class="sales-item"><strong>{{ $item->name }}</strong><span>{{ number_format($item->units_sold) }} sold · {{ \App\Support\Display::money($item->sales_total) }}</span></div>
                    @empty
                        <div class="empty-analytics">No POS sales yet. Complete a checkout to show best sellers.</div>
                    @endforelse
                </div>
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">{{ strtoupper($chartPeriodLabel) }} VIEW · {{ strtoupper($chartRangeLabel) }}</span><h2>{{ $salesPeriod === 'day' ? 'Sales for a Specific Date' : ($salesPeriod === 'year' ? 'Sales Month by Month' : 'Sales Day by Day') }}</h2></div>
                    <div class="chart-period-menu" data-chart-period-menu>
                        <button class="chart-period-toggle" type="button" data-chart-period-toggle aria-label="Choose sales chart period" aria-haspopup="menu" aria-expanded="false"><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                        <div class="chart-period-options" data-chart-period-options role="menu" hidden>
                            <a href="{{ route('admin.analytics', ['period' => 'day', 'range' => $selectedTransactionDate->toDateString()]) }}#transaction-history" role="menuitem" class="{{ $salesPeriod === 'day' ? 'is-active' : '' }}" @if($salesPeriod === 'day') aria-current="page" @endif><strong>Specific date</strong><small>Choose one day from the calendar</small></a>
                            <a href="{{ route('admin.analytics', ['period' => 'week', 'range' => $periodRanges['week'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'week' ? 'is-active' : '' }}" @if($salesPeriod === 'week') aria-current="page" @endif><strong>Weekly view</strong><small>Choose a week · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['period' => 'month', 'range' => $periodRanges['month'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'month' ? 'is-active' : '' }}" @if($salesPeriod === 'month') aria-current="page" @endif><strong>Monthly view</strong><small>Choose a month · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['period' => 'year', 'range' => $periodRanges['year'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'year' ? 'is-active' : '' }}" @if($salesPeriod === 'year') aria-current="page" @endif><strong>Yearly view</strong><small>Choose a year · month by month</small></a>
                        </div>
                    </div>
                </div>
                @if($salesPeriod === 'day')
                    @include('admin.partials.transaction-calendar')
                @else
                <form class="chart-range-filter" method="GET" action="{{ route('admin.analytics') }}">
                    <input type="hidden" name="transaction_date" value="{{ $selectedTransactionDate->toDateString() }}">
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
                    <span>Select a day</span><strong>View its net sales total</strong>
                </div>
                <div class="day-chart-scroll" tabindex="0" role="region" aria-label="{{ $chartPeriodLabel }} sales chart; scroll horizontally to view all dates">
                <div class="day-chart period-{{ $salesPeriod }}" data-day-chart role="group" aria-label="Interactive {{ strtolower($chartPeriodLabel) }} bar chart of paid sales less approved refunds" style="--chart-columns: {{ $weeklySales->count() }}">
                    @foreach($weeklySales as $index => $day)
                        <button
                            class="day-column"
                            type="button"
                            data-day-bar
                            data-day="{{ $day['label'] }} · {{ $day['date'] }}"
                            data-total="{{ $day['total'] }}" data-gross="{{ $day['gross_sales'] }}" data-refunds="{{ $day['refunds'] }}"
                            style="--bar-height: {{ $day['total'] != 0 ? max(8, $day['percent']) : 2 }}%; --bar-index: {{ $index }}"
                            aria-label="{{ $day['label'] }}, {{ $day['date'] }}: {{ \App\Support\Display::money($day['total']) }} in net sales"
                        >
                            <strong class="day-value">{{ \App\Support\Display::money($day['total']) }}</strong>
                            <span class="day-bar-track"><i></i></span>
                            <span class="day-label">{{ $day['label'] }}</span>
                            <small>{{ $day['date'] }}</small>
                        </button>
                    @endforeach
                </div>
                </div>
                @endif
            </article>
        </section>

        <section class="panel analytics-panel velocity-panel" id="selling-speed">
            <div class="section-heading velocity-heading">
                <div><span class="section-kicker">SELLING SPEED</span><h2>Fast-Moving Item Ranking</h2><small>Sales velocity = units sold on paid receipts in the last 30 calendar days ÷ 30, including days with no sales. Ranked from highest to lowest; ties use purchase frequency.</small></div>
                <span class="period">Last 30 days · paid receipts only</span>
            </div>
            <div class="velocity-table-wrap">
                <table class="velocity-table">
                    <thead>
                        <tr><th>Rank</th><th>Product</th><th>Purchase frequency</th><th>Units sold</th><th>Sales value</th><th>Sales velocity (units/day)</th></tr>
                    </thead>
                    <tbody>
                    @forelse($speedRows as $index => $item)
                        <tr>
                            <td><span class="rank-badge rank-{{ $speedRows->firstItem() + $index }}">#{{ $speedRows->firstItem() + $index }}</span></td>
                            <td><strong>{{ $item->name }}</strong><small>{{ $item->sku }} · {{ $item->category ?: 'Uncategorized' }}</small></td>
                            <td><strong>{{ number_format($item->purchase_frequency) }}</strong><small>{{ Str::plural('paid receipt', $item->purchase_frequency) }}</small></td>
                            <td><strong>{{ number_format($item->units_sold) }}</strong><small>{{ number_format($item->units_per_purchase, 1) }} per purchase</small></td>
                            <td><strong>{{ \App\Support\Display::money($item->sales_total) }}</strong><small>sales value</small></td>
                            <td><strong>{{ number_format($item->units_per_day, 2) }}</strong><small>units per day</small></td>
                        </tr>
                    @empty
                        <tr><td colspan="6"><div class="empty-analytics">No paid product sales in the last 30 days. Rankings will appear after POS checkouts.</div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        @include('partials.ml-table-pagination', ['rows' => $speedRows, 'sizeName' => 'speed_rows', 'pageName' => 'speed_page', 'anchor' => 'selling-speed', 'tableLabel' => 'Selling speed'])
</section>

        <section class="analytics-grid inventory-analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">STOCK LEVELS</span><h2>Highest Stock</h2></div></div>
                <div class="sales-list compact-list">
                    @forelse($highestStock as $product)
                        <div class="sales-item"><strong>{{ $product->name }}</strong><span>{{ \App\Support\Display::count($product->current_stock, 'unit') }}</span></div>
                    @empty
                        <div class="empty-analytics">No products available.</div>
                    @endforelse
                </div>
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">STOCK LEVELS</span><h2>Lowest Stock</h2></div></div>
                <div class="sales-list compact-list">
                    @forelse($lowestStock as $product)
                        <div class="sales-item"><strong>{{ $product->name }}</strong><span>{{ \App\Support\Display::count($product->current_stock, 'unit') }} · {{ ucfirst($product->stock_status) }}</span></div>
                    @empty
                        <div class="empty-analytics">No products available.</div>
                    @endforelse
                </div>
            </article>
        </section>

        <section class="analytics-grid">
            <article class="panel analytics-panel ai-demand-panel" id="future-demand">
                <div class="future-outlook-header">
                    <div class="future-outlook-icon" aria-hidden="true"><i class="bi bi-stars"></i></div>
                    <div class="future-outlook-copy">
                        <div class="future-outlook-heading">
                            <div><span class="section-kicker">LOCAL MACHINE LEARNING</span><h2>Future Product Demand</h2></div>
                            <span class="future-model-status {{ $aiDemandForecast['available'] ? 'ready' : 'waiting' }}"><i aria-hidden="true"></i>{{ $aiDemandForecast['available'] ? 'Predictions ready' : 'Waiting for sales history' }}</span>
                        </div>
                        <p>Identify which products may be most in demand over the next 30 days. Products with the highest estimated sales appear first.</p>
                        @php($priorityProducts = collect($aiDemandForecast['items'])->filter(fn ($item) => $item['priority_rank'] !== null)->take(3))
                        @if($priorityProducts->isNotEmpty())
                            <p class="future-priority"><strong>Products to watch:</strong> {{ $priorityProducts->map(fn ($item) => $item['name'].' ('.$item['sku'].')')->implode(' · ') }}</p>
                        @else
                            <p class="future-priority">No future-demand priorities identified yet. {{ $aiDemandForecast['available'] ? 'No positive sales estimates are available.' : 'Keep recording POS sales to build history.' }}</p>
                        @endif
                        <details class="future-model-details">
                            <summary>About this update</summary>
                            <p>{{ $aiDemandForecast['model'] }} · No hosted API is used. Predictions are estimates, not recorded demand.</p>
                            @if($aiDemandForecast['available'])<p>Validation MAE: {{ \App\Support\Display::count($aiDemandForecast['validation_mae'], 'unit', null, 1) }}; previous-30-day baseline: {{ \App\Support\Display::count($aiDemandForecast['baseline_mae'], 'unit', null, 1) }}. {{ $aiDemandForecast['validation_samples'] }} later historical samples. Lower error is better.</p>@else<p>{{ $aiDemandForecast['message'] }}</p>@endif
                        </details>
                    </div>
                </div>
                <div class="future-outlook-table" tabindex="0" role="region" aria-label="Future product demand predictions">
                    <table>
                        <thead><tr><th scope="col">Product</th><th scope="col">Stock</th><th scope="col">30-day demand</th><th scope="col">Priority</th><th scope="col">Observations</th><th scope="col">Updated</th></tr></thead>
                        <tbody>
                            @forelse($forecastItems as $item)
                                <tr>
                                    <td><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }}</small></td>
                                    <td>{{ number_format($item['stock']) }}</td>
                                    <td>@if($item['predicted_units'] === null)<span class="future-pending">Waiting for history</span>@else<strong>{{ \App\Support\Display::count($item['predicted_units'], 'unit') }}</strong><small>{{ $item['trend'] }} · {{ number_format($item['recent_units']) }} sold in the past 30 days</small>@endif</td>
                                    <td>@if($item['priority_rank'] !== null)<span class="future-priority-badge">#{{ $item['priority_rank'] }} by expected sales</span>@else<span class="future-muted">—</span>@endif</td>
                                    <td>{{ $item['recommendation'] }}</td>
                                    <td>{{ $item['predicted_units'] === null ? '—' : \Carbon\Carbon::parse($aiDemandForecast['updated_at'])->diffForHumans() }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6">No products yet. Add products and record paid POS sales to build demand history.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @include('partials.ml-table-pagination', ['rows' => $forecastItems, 'sizeName' => 'forecast_rows', 'pageName' => 'forecast_page', 'anchor' => 'future-demand', 'tableLabel' => 'Future product demand'])
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading"><div><span class="section-kicker">OBSERVED DEMAND</span><h2>Most Requested Items</h2></div><span class="period">Past 30 days · actual sales</span></div>
                <div class="sales-list">
                    @forelse($demand as $item)
                        <div class="sales-item"><strong>{{ $item->name }}</strong><span>{{ \App\Support\Display::count($item->demand_units, 'unit') }} demanded</span></div>
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
        <section class="panel analytics-panel slow-products-panel" id="slow-products">
            <div class="section-heading">
                <div><span class="section-kicker">INVENTORY TURNOVER</span><h2><i class="bi bi-hourglass-split" aria-hidden="true"></i> Slow-Moving / Non-moving Products</h2><small>Based on paid sales over the last 30 and 90 days, stock levels, and inventory age.</small></div>
                <a href="{{ route('admin.dead-stock', ['classification' => 'slow']) }}">Review products <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            </div>
            <div class="velocity-table-wrap">
                <table class="velocity-table">
                    <thead><tr><th>Product</th><th>Stock</th><th>30-Day Sales</th><th>Last Sale</th><th>Stock Cost</th><th>Recommendation</th></tr></thead>
                    <tbody>
                        @forelse($slowRows as $item)
                            <tr><td><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }}</small></td><td>{{ number_format($item['stock']) }}</td><td>{{ \App\Support\Display::count($item['monthly_units'], 'unit') }}</td><td>{{ $item['last_sale'] }}</td><td>{{ $item['total_cost'] }}</td><td>{{ $item['recommendation'] }}</td></tr>
                        @empty
                            <tr><td colspan="6" class="empty-analytics">No slow-moving products detected. Dead stock is tracked separately on the Dead Stock page.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @include('partials.ml-table-pagination', ['rows' => $slowRows, 'sizeName' => 'slow_rows', 'pageName' => 'slow_page', 'anchor' => 'slow-products', 'tableLabel' => 'Slow-moving products'])
</section>
    </main>
</div>
@include('partials.login-stock-alert')
</body>
</html>
