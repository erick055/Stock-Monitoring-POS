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
    <title>Dead Stock | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/dead-stock.css','resources/js/dashboard.js','resources/js/dead-stock.js'])
</head>
<body>
<div class="dashboard-shell dead-stock-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index=> $item)
                <a class="nav-link {{ $index === 5 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
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
                <p>Automated scoring analyzes POS sales, stock aging, demand, and trapped capital.</p>
            </div>
        </header>

        <section class="stat-grid dead-stock-stats" aria-label="Dead stock summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel detail-panel">
            <div class="section-heading">
                <div><span class="section-kicker">RECOVERY TARGET</span><h2>Dead Stock Items</h2></div>
            </div>
            <div class="detail-list">
                @forelse($deadStockItems as $item)
                    <article class="detail-card">
                        <div>
                            <strong>{{ $item['name'] }}</strong>
                            <small>{{ $item['sku'] }} | {{ $item['stock'] }} units left | {{ $item['age'] }} | Last sale: {{ $item['last_sale'] }}</small>
                            <div class="ai-score">
                                <div><span>AI Dead Stock Score</span><strong>{{ $item['score'] }}/100</strong></div>
                                <div class="score-track"><i class="{{ $item['classification_class'] }}" style="width: {{ $item['score_width'] }}"></i></div>
                                <span class="ai-badge {{ $item['classification_class'] }}">{{ $item['classification'] }}</span>
                            </div>
                            <ul class="reason-list">
                                @foreach($item['reasons'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                            <p class="ai-recommendation">{{ $item['recommendation'] }}</p>
                            <div class="action-row">
                                <button type="button" class="mini-action" data-ai-action>Apply Discount</button>
                                <button type="button" class="mini-action muted" data-ai-action>Bundle Item</button>
                            </div>
                        </div>
                        <div class="detail-metric">
                            <span>Total Cost</span>
                            <strong>{{ $item['total_cost'] }}</strong>
                        </div>
                    </article>
                @empty
                    <div class="empty-dead-stock">No dead stock detected. Products have recent POS movement or no idle inventory.</div>
                @endforelse
            </div>
        </section>

        <section class="panel detail-panel">
            <div class="section-heading">
                <div><span class="section-kicker">TURNOVER WATCH</span><h2>Slow-Moving Items</h2></div>
            </div>
            <div class="detail-list">
                @forelse($slowMovingItems as $item)
                    <article class="detail-card">
                        <div>
                            <strong>{{ $item['name'] }}</strong>
                            <small>{{ $item['sku'] }} | {{ $item['stock'] }} units available | Last sale: {{ $item['last_sale'] }}</small>
                            <div class="ai-score">
                                <div><span>AI Risk Score</span><strong>{{ $item['score'] }}/100</strong></div>
                                <div class="score-track"><i class="{{ $item['classification_class'] }}" style="width: {{ $item['score_width'] }}"></i></div>
                                <span class="ai-badge {{ $item['classification_class'] }}">{{ $item['classification'] }}</span>
                            </div>
                            <ul class="reason-list">
                                @foreach($item['reasons'] as $reason)
                                    <li>{{ $reason }}</li>
                                @endforeach
                            </ul>
                            <p class="ai-recommendation">{{ $item['recommendation'] }}</p>
                        </div>
                        <div class="detail-metric">
                            <span>{{ $item['note'] }}</span>
                            <strong>{{ $item['velocity'] }}</strong>
                        </div>
                    </article>
                @empty
                    <div class="empty-dead-stock">No slow-moving items yet. Items with small recent POS sales will appear here.</div>
                @endforelse
            </div>
        </section>

        <section class="panel summary-panel">
            <div class="section-heading">
                <div><span class="section-kicker">RECOVERY GUIDANCE</span><h2>Recommendation Summary</h2></div>
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
