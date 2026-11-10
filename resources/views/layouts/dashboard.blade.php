<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $dashboard['role_name'] }} Dashboard | MotoSync</title>
    @vite(['resources/css/dashboard.css', 'resources/css/responsive.css', 'resources/js/dashboard.js'])
    @include('partials.appearance')
</head>
<body>
<div class="dashboard-shell" data-dashboard>
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $dashboard['role_name'] }} navigation">
            @foreach($dashboard['navigation'] as $index => $item)
                <a class="nav-link {{ $index === 0 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ $dashboard['initials'] }}</span>
            <div><strong>{{ $dashboard['display_name'] }}</strong><small>{{ $dashboard['role_name'] }}</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main">
        <header class="topbar">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div class="dashboard-heading"><p class="welcome">Welcome back, {{ $dashboard['first_name'] }}</p><h1>{{ $dashboard['role_name'] }} Dashboard</h1><p class="dashboard-description">{{ $dashboard['description'] }}</p></div>
            <div class="top-actions">
                <span class="live-status"><i></i> Snapshot <small>Updated {{ $dashboard['updated_at']->format('h:i A') }}</small></span>
                <button type="button" data-dashboard-refresh aria-label="Refresh dashboard" title="Refresh dashboard"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i></button>
                <a class="notification-button" href="{{ $dashboard['notification_url'] }}" aria-label="{{ $dashboard['notification_count'] }} dashboard notifications">
                    <i class="bi bi-bell" aria-hidden="true"></i>
                    @if($dashboard['notification_count'] > 0)<b>{{ min(99, $dashboard['notification_count']) }}</b>@endif
                </a>
                <span class="date">{{ now()->format('M d, Y') }}</span>
            </div>
        </header>

        <section class="stat-grid" aria-label="Dashboard summary">
            @foreach($dashboard['stats'] as $stat)
                <article class="stat-card {{ $stat[3] }}"><div class="stat-head"><span>{{ $stat[0] }}</span><span class="trend-dot"></span></div><strong>{{ $stat[1] }}</strong><small>{{ $stat[2] }}</small></article>
            @endforeach
        </section>
        @if(isset($dashboard['sales_summary']))
            <section class="panel dashboard-sales-breakdown" aria-labelledby="sales-breakdown-title">
                <div class="section-heading"><div><span class="section-kicker">SALES SUMMARY</span><h2 id="sales-breakdown-title">Sales breakdown</h2></div><span class="period">{{ $dashboard['sales_period'] }}</span></div>
                <dl class="dashboard-sales-metrics">
                    @foreach([['Gross sales', 'gross_sales'], ['Refunds', 'refunds'], ['Net sales', 'net_sales']] as [$label, $metric])
                        <div aria-label="{{ $label }}: {{ \App\Support\Display::money($dashboard['sales_summary'][$metric]) }}"><dt>{{ $label }}</dt><dd>{{ \App\Support\Display::money($dashboard['sales_summary'][$metric]) }}</dd></div>
                    @endforeach
                </dl>
            </section>
        @endif

        @if(isset($dashboard['sales_target']))
            @include('partials.sales-target-card', ['salesTarget' => $dashboard['sales_target']])
        @endif
        <section class="panel modules-panel">
            <div class="section-heading"><div><span class="section-kicker">QUICK ACCESS</span><h2>{{ $dashboard['modules_title'] }}</h2></div></div>
            <div class="module-grid {{ count($dashboard['modules']) <= 4 ? 'compact-module-grid' : '' }}">
                @foreach($dashboard['modules'] as $module)
                    <a class="module-card" href="{{ url($module[3]) }}">
                        <span class="module-icon"><i class="bi bi-{{ $module[0] }}" aria-hidden="true"></i></span>
                        <div><strong>{{ $module[1] }}</strong><small>{{ $module[2] }}</small></div>
                        <span class="arrow"><i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="dashboard-lower">
            <section class="panel performance-panel">
                <div class="section-heading"><div><span class="section-kicker">{{ $dashboard['chart_kicker'] }}</span><h2>{{ $dashboard['chart_title'] }}</h2></div><span class="period">{{ \App\Support\Display::money(collect($dashboard['chart'])->sum('value')) }} total</span></div>
                <div class="dashboard-chart-scroll" tabindex="0" role="region" aria-label="Net sales for the last seven days">
                <div class="chart">
                    @foreach($dashboard['chart'] as $day)
                        <div class="bar-column {{ $day['value'] < 0 ? 'negative' : '' }}" title="{{ $day['date'] }}: {{ \App\Support\Display::money($day['value']) }}">
                            <b class="bar-value">{{ $day['value'] != 0 ? '₱'.number_format($day['value'], 0) : '—' }}</b>
                            <div class="bar-track"><span style="height: {{ $day['height'] }}%"></span></div>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
                </div>
                @if(collect($dashboard['chart'])->contains(fn ($day) => $day['value'] < 0))<p class="dashboard-chart-note">Red bars indicate negative net sales after refunds.</p>@endif
            </section>
            <section class="panel activity-panel">
                <div class="section-heading"><div><span class="section-kicker">RECENT RECORDS</span><h2>Recent Activity</h2></div><span class="period">Latest records</span></div>
                <div class="activity-list">
                    @forelse($dashboard['activity'] as $item)
                        <a class="activity-item" href="{{ $item['url'] }}"><span class="activity-icon {{ $item['color'] }}">{{ $loop->iteration }}</span><div><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></div><time>{{ $item['time'] }}</time></a>
                    @empty
                        <div class="activity-empty"><strong>No activity yet</strong><small>Sales and inventory changes appear here. Use Refresh to see the latest records.</small></div>
                    @endforelse
                </div>
            </section>
        </div>
    </main>
</div>
@include('partials.login-stock-alert')
</body>
</html>
