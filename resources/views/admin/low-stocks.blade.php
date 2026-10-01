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
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Alerts | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/low-stocks.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/low-stocks.js'])
</head>
<body>
<div class="dashboard-shell alerts-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 5 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main alerts-main">
        <header class="alerts-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
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
                <div><span class="section-kicker">LIVE INVENTORY WATCH</span><h2>Active Stock Alerts</h2><small>{{ number_format($activeAlerts->total()) }} matching products</small></div>
            </div>
            <form class="data-toolbar" method="GET" action="{{ route('admin.low-stocks') }}">
                <label class="compact-search"><span>Search</span><input name="search" value="{{ $search }}" placeholder="Product, SKU, or category"></label>
                <label><span>Status</span><select name="status"><option value="all" @selected($status === 'all')>All alerts</option><option value="critical" @selected($status === 'critical')>Critical</option><option value="warning" @selected($status === 'warning')>Warning</option></select></label>
                <label><span>Rows</span><select name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></label>
                <button type="submit">Apply</button>
                @if($search || $status !== 'all' || $perPage !== 25)<a href="{{ route('admin.low-stocks') }}">Reset</a>@endif
            </form>
            <div class="table-wrap compact-alert-table">
                <table>
                    <thead><tr><th>Product</th><th>Status</th><th>Stock</th><th>Reorder At</th><th>Level</th><th>Suggested Action</th></tr></thead>
                    <tbody>
                    @forelse($activeAlerts as $alert)
                        <tr>
                            <td><strong>{{ $alert['name'] }}</strong><small>{{ $alert['sku'] }}</small></td>
                            <td><span class="alert-badge {{ $alert['status_class'] }}">{{ $alert['status'] }}</span></td>
                            <td><strong>{{ number_format($alert['stock']) }}</strong></td>
                            <td>{{ number_format($alert['threshold']) }}</td>
                            <td><div class="compact-level"><i class="{{ $alert['status_class'] }}" style="width:{{ $alert['fill'] }}"></i></div></td>
                            <td>{{ implode(' / ', $alert['actions']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-table">No products match the selected alert filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($activeAlerts->hasPages())
                <nav class="compact-pagination" aria-label="Stock alert pages">
                    @if($activeAlerts->onFirstPage())<span>Previous</span>@else<a href="{{ $activeAlerts->previousPageUrl() }}">Previous</a>@endif
                    <strong>Page {{ $activeAlerts->currentPage() }} of {{ $activeAlerts->lastPage() }}</strong>
                    @if($activeAlerts->hasMorePages())<a href="{{ $activeAlerts->nextPageUrl() }}">Next</a>@else<span>Next</span>@endif
                </nav>
            @endif
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
                <form method="POST" action="{{ route('admin.low-stocks.run-now') }}">@csrf<button class="check-button" type="submit"><span aria-hidden="true"><i class="bi bi-lightning-charge"></i></span> Run alert check now</button></form>
            </div>
            <form class="notification-settings" method="POST" action="{{ route('admin.low-stocks.settings') }}">
                @csrf
                <div class="settings-grid">
                    <label class="setting-card">
                        <span>Email Notification</span><small>Send immediate warning and critical alerts</small>
                        <input type="hidden" name="email_enabled" value="0"><input name="email_enabled" value="1" type="checkbox" @checked($settings->email_enabled)>
                    </label>
                    <label class="setting-card">
                        <span>Daily Summary</span><small>Email one consolidated report each day</small>
                        <input type="hidden" name="daily_summary_enabled" value="0"><input name="daily_summary_enabled" value="1" type="checkbox" @checked($settings->daily_summary_enabled)>
                    </label>
                </div>
                <div class="notification-destinations">
                    <label>Email destination<input name="notification_email" type="email" value="{{ old('notification_email', $settings->notification_email) }}" placeholder="owner@example.com"></label>
                    <label>Daily summary time<input name="daily_summary_time" type="time" value="{{ old('daily_summary_time', $settings->daily_summary_time) }}" required></label>
                    <button class="apply-button" type="submit">Save settings</button>
                </div>
                @if($errors->any())<div class="settings-error">{{ $errors->first() }}</div>@endif
            </form>
        </section>

        <section class="panel delivery-panel">
            <div class="section-heading"><div><span class="section-kicker">DELIVERY AUDIT</span><h2>Notification History</h2></div></div>
            <div class="table-wrap delivery-table">
                <table>
                    <thead><tr><th>Time</th><th>Channel</th><th>Type</th><th>Recipient</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    @forelse($deliveries as $delivery)
                        <tr>
                            <td>{{ $delivery->created_at->format('M d, h:i A') }}</td>
                            <td>{{ strtoupper($delivery->channel) }}</td>
                            <td>{{ str_replace('_', ' ', ucfirst($delivery->alert_type)) }}</td>
                            <td>{{ $delivery->recipient }}</td>
                            <td><span class="delivery-status {{ $delivery->status }}">{{ ucfirst($delivery->status) }}</span></td>
                            <td title="{{ $delivery->error ?: $delivery->message }}">{{ mb_strimwidth($delivery->error ?: $delivery->message, 0, 70, '...') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="empty-deliveries">No notifications have been attempted yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        @if(session('success'))<div class="alerts-toast" data-alerts-toast role="status">{{ session('success') }}</div>@endif
    </main>
</div>
</body>
</html>
