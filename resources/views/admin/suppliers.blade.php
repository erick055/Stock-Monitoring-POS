@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '/admin/deadstock'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
];
$summary = [
    ['ACTIVE SUPPLIER', '12', 'Suppliers with active pricing updates', 'purple'],
    ['PRICE CHANGES', '18', 'Changes tracked this month', 'orange'],
    ['AVG MARGIN', '24%', 'Across priority items', 'violet'],
];
$supplierProducts = [
    ['supplier' => 'ABC Motor Parts', 'product' => 'Engine Oil', 'current' => 'P250', 'previous' => 'P230', 'change' => '+8.7%', 'updated' => 'Jul 3'],
    ['supplier' => 'ABC Motor Parts', 'product' => 'Oil Filter', 'current' => 'P180', 'previous' => 'P165', 'change' => '+9.1%', 'updated' => 'Jul 2'],
];
$slowMoving = [
    ['supplier' => 'Superior Tire Co.', 'product' => 'Tire (Front)', 'current' => 'P1,200', 'previous' => 'P1,150', 'change' => '+4.3%', 'updated' => 'Jul 1'],
];
$recommendations = [
    'Hold purchases on slow-moving items until current stock levels improve.',
    'Negotiate bulk pricing for high-volume parts with repeated monthly increases.',
    'Review margin changes before finalizing the next purchase order.',
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Price | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/suppliers.css','resources/js/dashboard.js','resources/js/suppliers.js'])
</head>
<body>
<div class="dashboard-shell suppliers-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 7 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main suppliers-main">
        <header class="suppliers-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">SUPPLIER COST TRACKING</p>
                <h1>Supplier Price</h1>
                <p>Track supplier prices, margin changes, and pricing recommendations.</p>
            </div>
        </header>

        <section class="stat-grid suppliers-stats" aria-label="Supplier price summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel suppliers-panel">
            <div class="section-heading">
                <div><span class="section-kicker">SUPPLIER CATALOG</span><h2>Supplier Products and Pricing</h2></div>
            </div>
            <div class="pricing-list">
                @foreach($supplierProducts as $item)
                    <article class="pricing-card">
                        <div class="supplier-line">
                            <strong>{{ $item['supplier'] }}</strong>
                            <small>{{ $item['product'] }}</small>
                        </div>
                        <div class="pricing-pill">{{ $item['current'] }}<span>Current Price</span></div>
                        <div class="pricing-pill">{{ $item['previous'] }}<span>Previous Price</span></div>
                        <div class="pricing-pill accent">{{ $item['change'] }}<span>Change</span></div>
                        <div class="pricing-pill">{{ $item['updated'] }}<span>Last Update</span></div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="panel suppliers-panel">
            <div class="section-heading">
                <div><span class="section-kicker">SLOW MOVING WATCH</span><h2>Slow-Moving Items</h2></div>
            </div>
            <div class="pricing-list">
                @foreach($slowMoving as $item)
                    <article class="pricing-card compact">
                        <div class="supplier-line">
                            <strong>{{ $item['supplier'] }}</strong>
                            <small>{{ $item['product'] }}</small>
                        </div>
                        <div class="pricing-pill">{{ $item['current'] }}<span>Current Price</span></div>
                        <div class="pricing-pill">{{ $item['previous'] }}<span>Previous Price</span></div>
                        <div class="pricing-pill accent">{{ $item['change'] }}<span>Change</span></div>
                        <div class="pricing-pill">{{ $item['updated'] }}<span>Last Update</span></div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="panel recommendation-panel">
            <div class="section-heading">
                <div><span class="section-kicker">AI RECOMMENDATION</span><h2>AI Recommendation Summary</h2></div>
                <button class="apply-button" type="button" data-refresh-suppliers>Refresh</button>
            </div>
            <div class="recommendation-list">
                @foreach($recommendations as $recommendation)
                    <p>{{ $recommendation }}</p>
                @endforeach
            </div>
        </section>

        <div class="suppliers-toast" data-suppliers-toast hidden role="status">Supplier price summary refreshed in UI preview.</div>
    </main>
</div>
</body>
</html>