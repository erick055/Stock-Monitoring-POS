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
    <title>Stock Alerts | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/low-stocks.css','resources/js/dashboard.js','resources/js/low-stocks.js'])
</head>
<body>
<div class="dashboard-shell alerts-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 4 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main alerts-main">
        <header class="alerts-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">LIVE INVENTORY ALERTS</p>
                <h1>Stock Alerts and Monitoring</h1>
                <p>Monitor critical inventory levels from Stock Management and POS checkout movement.</p>
            </div>
        </header>

        <section class="stat-grid alerts-stats" aria-label="Stock alert summary">
            <article class="stat-card red"><div class="stat-head"><span>CRITICAL LOW STOCK</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['critical_low']) }}</strong><small>At or below reorder level</small></article>
            <article class="stat-card orange"><div class="stat-head"><span>LOW STOCK WARNING</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['low_warning']) }}</strong><small>Near reorder point</small></article>
            <article class="stat-card purple"><div class="stat-head"><span>AVG DAILY SALES</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['avg_daily_sales'], 1) }} units</strong><small>Based on last 7 days POS sales</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>AVG WEEKLY DEMAND</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['avg_weekly_demand']) }} units</strong><small>Rolling 7-day demand</small></article>
        </section>

        <section class="panel alerts-panel">
            <div class="section-heading">
                <div><span class="section-kicker">LIVE INVENTORY WATCH</span><h2>Active Stock Alerts</h2></div>
            </div>
            <div class="alert-list">
                @forelse($activeAlerts as $alert)
                    <article class="alert-card {{ $alert['status_class'] }}">
                        <div class="alert-meta">
                            <div>
                                <strong>{{ $alert['name'] }}</strong>
                                <small>{{ $alert['sku'] }} | {{ $alert['stock'] }} left | Reorder at {{ $alert['threshold'] }}</small>
                            </div>
                            <span class="alert-badge {{ $alert['status_class'] }}">{{ $alert['status'] }}</span>
                        </div>
                        <div class="alert-bar"><i style="width: {{ $alert['fill'] }}"></i></div>
                        <div class="alert-actions">
                            @foreach($alert['actions'] as $action)
                                <span>{{ $action }}</span>
                            @endforeach
                        </div>
                    </article>
                @empty
                    <div class="empty-alert">No low stock alerts right now. Inventory levels are healthy.</div>
                @endforelse
            </div>
        </section>

        <section class="panel fast-panel">
            <div class="section-heading">
                <div><span class="section-kicker">MOVEMENT SIGNAL</span><h2>Fast Moving Item Analysis</h2></div>
            </div>
            <div class="fast-list">
                @forelse($fastMoving as $item)
                    <article class="fast-card">
                        <div>
                            <strong>{{ $item['name'] }}</strong>
                            <small>{{ $item['sku'] }} | {{ $item['weekly'] }}</small>
                        </div>
                        <div><span>Turnover Trend</span><strong>{{ $item['turnover'] }}</strong></div>
                        <div><span>Days Left</span><strong>{{ $item['days_left'] }}</strong></div>
                        <span class="fast-badge">{{ $item['status'] }}</span>
                    </article>
                @empty
                    <div class="empty-alert">No POS sales yet. Fast-moving items will appear after staff checkout transactions.</div>
                @endforelse
            </div>
        </section>

        <section class="panel settings-panel">
            <div class="section-heading">
                <div><span class="section-kicker">NOTIFICATION CONTROL</span><h2>Alert Settings</h2></div>
                <button class="apply-button" type="button" data-apply-settings>Apply</button>
            </div>
            <div class="settings-grid">
                @foreach($settings as $setting)
                    <label class="setting-card">
                        <span>{{ $setting[0] }}</span>
                        <small>{{ $setting[1] }}</small>
                        <input type="checkbox" checked>
                    </label>
                @endforeach
            </div>
        </section>

        <div class="alerts-toast" data-alerts-toast hidden role="status">Alert settings saved in UI preview.</div>
    </main>
</div>
</body>
</html>
