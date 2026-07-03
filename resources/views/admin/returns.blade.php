@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alert','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Return & Damage','#'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
];
$summary = [
    ['TOTAL RETURN THIS MONTH', '8', 'Processed customer returns', 'purple'],
    ['DAMAGE ITEMS', '3', 'Items flagged for replacement', 'violet'],
    ['RETURN RATES', '35%', 'Based on reported incidents', 'orange'],
];
$customerReturns = [
    [
        'item' => 'Tire (Harap)',
        'order' => 'Order: ORD-001',
        'quantity' => 'Qty: 1',
        'reason' => 'Reason: Defective',
        'amount' => 'P 1,500.00',
        'status' => 'Approved',
        'action' => 'Review',
    ],
];
$damageLogs = [
    [
        'item' => 'Air Filter',
        'note' => 'Water Damaged',
        'quantity' => '3 Units',
        'date' => '2026-07-11',
        'replacement' => 'Replacement Status: Ordered',
        'action' => 'Update',
    ],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return & Damage | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/returns.css','resources/js/dashboard.js','resources/js/returns.js'])
</head>
<body>
<div class="dashboard-shell returns-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 6 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main returns-main">
        <header class="returns-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">HANDLE RETURNS, DAMAGES, AND REFUNDS</p>
                <h1>Return &amp; Damage Management</h1>
                <p>Manage return requests, damaged goods, and replacement tracking.</p>
            </div>
        </header>

        <section class="stat-grid returns-stats" aria-label="Returns summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel returns-panel">
            <div class="section-heading">
                <div><span class="section-kicker">CUSTOMER CASES</span><h2>Customer Returns</h2></div>
                <button class="panel-action" type="button" data-return-action>+ Product Return</button>
            </div>
            <div class="case-list">
                @foreach($customerReturns as $return)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $return['item'] }}</strong>
                            <small>{{ $return['order'] }}</small>
                            <div class="case-meta"><span>{{ $return['quantity'] }}</span><span>{{ $return['reason'] }}</span></div>
                        </div>
                        <div class="case-side">
                            <strong class="amount">{{ $return['amount'] }}</strong>
                            <span class="status approved">{{ $return['status'] }}</span>
                            <button type="button" class="mini-button">{{ $return['action'] }}</button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <section class="panel returns-panel">
            <div class="section-heading">
                <div><span class="section-kicker">INTERNAL DAMAGE TRACKER</span><h2>Damage Log</h2></div>
                <button class="panel-action" type="button" data-return-action>+ Product Return</button>
            </div>
            <div class="case-list">
                @foreach($damageLogs as $log)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $log['item'] }}</strong>
                            <small>{{ $log['note'] }}</small>
                            <div class="case-meta"><span>{{ $log['replacement'] }}</span></div>
                        </div>
                        <div class="case-side">
                            <strong>{{ $log['quantity'] }}</strong>
                            <small>{{ $log['date'] }}</small>
                            <button type="button" class="mini-button success">{{ $log['action'] }}</button>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>

        <div class="returns-toast" data-returns-toast hidden role="status">Return and damage action saved in UI preview.</div>
    </main>
</div>
</body>
</html>