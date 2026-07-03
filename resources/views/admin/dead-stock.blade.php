@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '#'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','User Management','/admin/users'], ['⚙','Settings','/admin/settings'],
];
$summary = [
    ['DEAD STOCK ITEM', '1', 'Aged items with no recent movement', 'purple'],
    ['SLOW MOVING', '1', 'Products with weak monthly turnover', 'violet'],
    ['TRAPPED CAPITAL', 'P15,000', 'Estimated value tied to idle stock', 'orange'],
];
$deadStockItem = [
    'name' => 'Engine Block Set (Old Model)',
    'sku' => 'ENG-BLK-OLD',
    'total_cost' => 'P15,000',
    'age' => '14 months in storage',
];
$slowMovingItem = [
    'name' => 'Premium Exhaust System',
    'sku' => 'EXH-PRM-22',
    'velocity' => '2 units / month',
    'note' => 'Monthly Velocity',
];
$recommendations = [
    'Bundle this item with compatible maintenance parts for clearance campaigns.',
    'Offer a time-limited discount to recover storage space and idle capital.',
    'Pause reordering until existing stock movement improves.',
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dead Stock | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/dead-stock.css','resources/js/dashboard.js','resources/js/dead-stock.js'])
</head>
<body>
<div class="dashboard-shell dead-stock-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 5 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main dead-stock-main">
        <header class="dead-stock-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">AI-POWERED INVENTORY OPTIMIZATION</p>
                <h1>Dead Stock Detection</h1>
                <p>Review stagnant inventory, slow-moving items, and recovery recommendations.</p>
            </div>
        </header>

        <section class="stat-grid dead-stock-stats" aria-label="Dead stock summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel detail-panel">
            <div class="section-heading">
                <div><span class="section-kicker">RECOVERY TARGET</span><h2>Dead Stock Item</h2></div>
            </div>
            <article class="detail-card">
                <div>
                    <strong>{{ $deadStockItem['name'] }}</strong>
                    <small>{{ $deadStockItem['sku'] }} | {{ $deadStockItem['age'] }}</small>
                    <div class="action-row">
                        <button type="button" class="mini-action" data-ai-action>Apply Discount</button>
                        <button type="button" class="mini-action muted" data-ai-action>Remove from Sale</button>
                    </div>
                </div>
                <div class="detail-metric">
                    <span>Total Cost</span>
                    <strong>{{ $deadStockItem['total_cost'] }}</strong>
                </div>
            </article>
        </section>

        <section class="panel detail-panel">
            <div class="section-heading">
                <div><span class="section-kicker">TURNOVER WATCH</span><h2>Slow-Moving Items</h2></div>
            </div>
            <article class="detail-card">
                <div>
                    <strong>{{ $slowMovingItem['name'] }}</strong>
                    <small>{{ $slowMovingItem['sku'] }}</small>
                </div>
                <div class="detail-metric">
                    <span>{{ $slowMovingItem['note'] }}</span>
                    <strong>{{ $slowMovingItem['velocity'] }}</strong>
                </div>
            </article>
        </section>

        <section class="panel summary-panel">
            <div class="section-heading">
                <div><span class="section-kicker">AI RECOMMENDATION</span><h2>AI Recommendation Summary</h2></div>
                <button class="apply-button" type="button" data-refresh-summary>Refresh</button>
            </div>
            <div class="recommendation-list">
                @foreach($recommendations as $recommendation)
                    <p>{{ $recommendation }}</p>
                @endforeach
            </div>
        </section>

        <div class="dead-stock-toast" data-dead-stock-toast hidden role="status">Recommendation summary refreshed in UI preview.</div>
    </main>
</div>
</body>
</html>
