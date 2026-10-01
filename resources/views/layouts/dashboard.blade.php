<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $dashboard['role_name'] }} Dashboard | MotoSync</title>
    @vite(['resources/css/dashboard.css', 'resources/css/responsive.css', 'resources/js/dashboard.js'])
</head>
<body>
<div class="dashboard-shell" data-dashboard data-refresh-ms="60000">
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
            <div><p class="welcome">Welcome back, {{ $dashboard['first_name'] }}</p><h1>{{ $dashboard['role_name'] }} Dashboard</h1><p>{{ $dashboard['description'] }}</p></div>
            <div class="top-actions">
                <span class="live-status"><i></i> Live <small>Updated {{ $dashboard['updated_at']->format('h:i A') }}</small></span>
                <button type="button" data-dashboard-refresh aria-label="Refresh dashboard" title="Refresh live data">↻</button>
                <a class="notification-button" href="{{ $dashboard['notification_url'] }}" aria-label="{{ $dashboard['notification_count'] }} dashboard notifications">
                    !
                    @if($dashboard['notification_count'] > 0)<span class="notification-dot"></span><b>{{ min(99, $dashboard['notification_count']) }}</b>@endif
                </a>
                <span class="date">{{ now()->format('M d, Y') }}</span>
            </div>
        </header>

        <section class="stat-grid" aria-label="Dashboard summary">
            @foreach($dashboard['stats'] as $stat)
                <article class="stat-card {{ $stat[3] }}"><div class="stat-head"><span>{{ $stat[0] }}</span><span class="trend-dot"></span></div><strong>{{ $stat[1] }}</strong><small>{{ $stat[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel modules-panel">
            <div class="section-heading"><div><span class="section-kicker">QUICK ACCESS</span><h2>{{ $dashboard['modules_title'] }}</h2></div><button type="button"><i class="bi bi-three-dots" aria-hidden="true"></i></button></div>
            <div class="module-grid">
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
                <div class="section-heading"><div><span class="section-kicker">{{ $dashboard['chart_kicker'] }}</span><h2>{{ $dashboard['chart_title'] }}</h2></div><span class="period">₱{{ number_format(collect($dashboard['chart'])->sum('value'), 2) }} total</span></div>
                <div class="chart" aria-label="Real sales totals for the last seven days">
                    @foreach($dashboard['chart'] as $day)
                        <div class="bar-column" title="{{ $day['date'] }}: ₱{{ number_format($day['value'], 2) }}">
                            <b class="bar-value">{{ $day['value'] > 0 ? '₱'.number_format($day['value'], 0) : '—' }}</b>
                            <div class="bar-track"><span style="height: {{ $day['height'] }}%"></span></div>
                            <small>{{ $day['label'] }}</small>
                        </div>
                    @endforeach
                </div>
            </section>
            <section class="panel activity-panel">
                <div class="section-heading"><div><span class="section-kicker">LIVE UPDATES</span><h2>Recent Activity</h2></div><span class="period">Latest records</span></div>
                <div class="activity-list">
                    @forelse($dashboard['activity'] as $item)
                        <a class="activity-item" href="{{ $item['url'] }}"><span class="activity-icon {{ $item['color'] }}">{{ $loop->iteration }}</span><div><strong>{{ $item['title'] }}</strong><small>{{ $item['detail'] }}</small></div><time>{{ $item['time'] }}</time></a>
                    @empty
                        <div class="activity-empty"><strong>No activity yet</strong><small>New sales and inventory records will appear here automatically.</small></div>
                    @endforelse
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
