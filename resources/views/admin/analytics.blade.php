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
    @vite(['resources/css/dashboard.css','resources/css/analytics.css','resources/js/dashboard.js','resources/js/analytics.js'])
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
                <p>Highest and lowest stock, sales, demand, best sellers, and weekly day-by-day performance.</p>
            </div>
            <div class="header-tools">
                <span class="period-select">Live POS Data</span>
                <button class="more-button" type="button">&#8226;&#8226;&#8226;</button>
            </div>
        </header>

        <section class="stat-grid analytics-stats" aria-label="Analytics summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL SALES</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['total_sales'], 2) }}</strong><small>From completed POS sales</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TRANSACTIONS</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['transactions']) }}</strong><small>Paid receipts recorded</small></article>
            <article class="stat-card red"><div class="stat-head"><span>AVG. ORDER VALUE</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['average_order_value'], 2) }}</strong><small>Average POS checkout</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>PROFIT MARGIN</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['profit_margin'], 1) }}%</strong><small>Based on product cost vs sales</small></article>
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
                    <div><span class="section-kicker">WEEKLY VIEW</span><h2>Sales Day by Day</h2></div>
                    <button type="button">&#8226;&#8226;&#8226;</button>
                </div>
                <div class="day-chart" data-day-chart>
                    @foreach($weeklySales as $day)
                        <div class="day-row">
                            <span class="day-label">{{ $day['label'] }}</span>
                            <div class="day-track" title="{{ $day['date'] }}"><i style="width: {{ $day['percent'] }}%"></i></div>
                            <strong>₱{{ number_format($day['total'], 2) }}</strong>
                        </div>
                    @endforeach
                </div>
            </article>
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
