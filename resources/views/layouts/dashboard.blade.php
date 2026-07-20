<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $dashboard['role_name'] }} Dashboard | MotoSync</title>
    @vite(['resources/css/dashboard.css', 'resources/js/dashboard.js'])
</head>
<body>
<div class="dashboard-shell" data-dashboard>
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $dashboard['role_name'] }} navigation">
            @foreach($dashboard['navigation'] as $index => $item)
                <a class="nav-link {{ $index === 0 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ $dashboard['initials'] }}</span>
            <div><strong>{{ $dashboard['display_name'] }}</strong><small>{{ $dashboard['role_name'] }}</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main">
        <header class="topbar">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div><p class="welcome">Welcome back, {{ $dashboard['first_name'] }}</p><h1>{{ $dashboard['role_name'] }} Dashboard</h1><p>{{ $dashboard['description'] }}</p></div>
            <div class="top-actions"><button type="button" aria-label="Notifications">&#9679;<span class="notification-dot"></span></button><span class="date">{{ now()->format('M d, Y') }}</span></div>
        </header>

        <section class="stat-grid" aria-label="Dashboard summary">
            @foreach($dashboard['stats'] as $stat)
                <article class="stat-card {{ $stat[3] }}"><div class="stat-head"><span>{{ $stat[0] }}</span><span class="trend-dot"></span></div><strong>{{ $stat[1] }}</strong><small>{{ $stat[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel modules-panel">
            <div class="section-heading"><div><span class="section-kicker">QUICK ACCESS</span><h2>{{ $dashboard['modules_title'] }}</h2></div><button type="button">&#8226;&#8226;&#8226;</button></div>
            <div class="module-grid">
                @foreach($dashboard['modules'] as $module)
                    <a class="module-card" href="{{ url($module[3]) }}"><span class="module-icon">{{ $module[0] }}</span><div><strong>{{ $module[1] }}</strong><small>{{ $module[2] }}</small></div><span class="arrow">&#8594;</span></a>
                @endforeach
            </div>
        </section>

        <div class="dashboard-lower">
            <section class="panel performance-panel">
                <div class="section-heading"><div><span class="section-kicker">{{ $dashboard['chart_kicker'] }}</span><h2>{{ $dashboard['chart_title'] }}</h2></div><span class="period">This week&#8984;</span></div>
                <div class="chart" aria-label="Sample weekly sales chart">
                    @foreach([42,68,52,84,64,91,76] as $height)
                        <div class="bar-column"><div class="bar-track"><span style="height: {{ $height }}%"></span></div><small>{{ ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][$loop->index] }}</small></div>
                    @endforeach
                </div>
            </section>
            <section class="panel activity-panel">
                <div class="section-heading"><div><span class="section-kicker">LIVE UPDATES</span><h2>Recent Activity</h2></div><a href="#">View all</a></div>
                <div class="activity-list">
                    @foreach($dashboard['activity'] as $item)
                        <div class="activity-item"><span class="activity-icon {{ $item[0] }}">{{ $loop->iteration }}</span><div><strong>{{ $item[1] }}</strong><small>{{ $item[2] }}</small></div><time>{{ $loop->iteration * 7 }}m</time></div>
                    @endforeach
                </div>
            </section>
        </div>
    </main>
</div>
</body>
</html>
