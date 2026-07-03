@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '/admin/deadstock'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
];
$topSales = [
    ['Engine Oil 1L', 'P5,320'],
    ['Tires', 'P8,320'],
    ['Bearing', 'P4,320'],
];
$salesByDay = [
    ['Mon', 520, '14%'],
    ['Tue', 920, '26%'],
    ['Wed', 1120, '32%'],
    ['Thu', 1320, '38%'],
    ['Fri', 2320, '67%'],
    ['Sat', 3320, '92%'],
    ['Sun', 4320, '100%'],
];
$stockFlow = [
    ['Added to Stock', '245 ITEMS', 'green'],
    ['Sold', '1,230 ITEMS', 'blue'],
    ['Returned', '12 ITEMS', 'orange'],
    ['Damaged', '8 ITEMS', 'red'],
    ['Current Stock', '3,456 ITEMS', 'violet'],
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
                <a class="nav-link" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
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
                <p class="welcome">PERFORMANCE INSIGHT</p>
                <h1>Sales &amp; Analytics Dashboard</h1>
                <p>Track sales movement, orders, and inventory performance.</p>
            </div>
            <div class="header-tools">
                <select class="period-select" data-period-select aria-label="Select reporting period">
                    <option>Today</option>
                    <option selected>This Week</option>
                    <option>This Month</option>
                </select>
                <button class="more-button" type="button">&#8226;&#8226;&#8226;</button>
            </div>
        </header>

        <section class="stat-grid analytics-stats" aria-label="Analytics summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL SALES</span><span class="trend-dot"></span></div><strong>P45,320</strong><small>+12% from last week</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TRANSACTIONS</span><span class="trend-dot"></span></div><strong>320</strong><small>+8% today</small></article>
            <article class="stat-card red"><div class="stat-head"><span>AVG. ORDER VALUE</span><span class="trend-dot"></span></div><strong>P320</strong><small>-3% vs average</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>PROFIT MARGIN</span><span class="trend-dot"></span></div><strong>35%</strong><small>+2.3% improvement</small></article>
        </section>

        <section class="analytics-grid">
            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">BEST SELLERS</span><h2>Top Sales Item</h2></div>
                    <button type="button">&#8226;&#8226;&#8226;</button>
                </div>
                <div class="sales-list">
                    @foreach($topSales as $item)
                        <div class="sales-item"><strong>{{ $item[0] }}</strong><span>{{ $item[1] }}</span></div>
                    @endforeach
                </div>
            </article>

            <article class="panel analytics-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">WEEKLY VIEW</span><h2>Sales by Day</h2></div>
                    <button type="button">&#8226;&#8226;&#8226;</button>
                </div>
                <div class="day-chart" data-day-chart>
                    @foreach($salesByDay as $day)
                        <div class="day-row">
                            <span class="day-label">{{ $day[0] }}</span>
                            <div class="day-track"><i style="width: {{ $day[2] }}"></i></div>
                            <strong>P{{ number_format($day[1]) }}</strong>
                        </div>
                    @endforeach
                </div>
            </article>
        </section>

        <section class="panel flow-panel">
            <div class="section-heading">
                <div><span class="section-kicker">INVENTORY MOVEMENT</span><h2>Stock Flow Summary</h2></div>
                <span class="period">Live snapshot</span>
            </div>
            <div class="flow-grid">
                @foreach($stockFlow as $item)
                    <article class="flow-card {{ $item[2] }}">
                        <small>{{ $item[0] }}</small>
                        <strong>{{ $item[1] }}</strong>
                    </article>
                @endforeach
            </div>
        </section>
    </main>
</div>
</body>
</html>