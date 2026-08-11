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
    @vite(['resources/css/dashboard.css','resources/css/dead-stock.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/dead-stock.js'])
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

        @if(session('success'))<div class="promotion-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="promotion-message error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="stat-grid dead-stock-stats" aria-label="Dead stock summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel detail-panel risk-inventory-panel">
            <div class="section-heading">
                <div><span class="section-kicker">AI RECOVERY QUEUE</span><h2>Inventory Unlikely to Sell Soon</h2><small>{{ number_format($riskItems->total()) }} matching products. AI suggests; only an administrator can approve an offer.</small></div>
            </div>
            <form class="data-toolbar" method="GET" action="{{ route('admin.dead-stock') }}">
                <label class="compact-search"><span>Search</span><input name="search" value="{{ $search }}" placeholder="Product or SKU"></label>
                <label><span>Risk</span><select name="classification"><option value="all" @selected($classification === 'all')>All risks</option><option value="dead" @selected($classification === 'dead')>Dead stock</option><option value="slow" @selected($classification === 'slow')>Slow moving</option></select></label>
                <label><span>Rows</span><select name="per_page">@foreach([10,25,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></label>
                <button type="submit">Apply</button>
                @if($search || $classification !== 'all' || $perPage !== 25)<a href="{{ route('admin.dead-stock') }}">Reset</a>@endif
            </form>
            <div class="table-wrap risk-table">
                <table>
                    <thead><tr><th>Product</th><th>Risk</th><th>Score</th><th>Stock</th><th>30-Day Sales</th><th>Last Sale</th><th>Capital</th><th>Analysis</th><th>Owner Action</th></tr></thead>
                    <tbody>
                    @forelse($riskItems as $item)
                        <tr>
                            <td><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }} | {{ $item['age'] }}</small></td>
                            <td><span class="ai-badge {{ $item['classification_class'] }}">{{ $item['classification'] }}</span></td>
                            <td><div class="table-score" title="{{ $item['classification'] === 'Dead Stock' ? 'AI Dead Stock Score' : 'AI Risk Score' }}"><strong>{{ $item['score'] }}</strong><div class="score-track"><i class="{{ $item['classification_class'] }}" style="width:{{ $item['score_width'] }}"></i></div></div></td>
                            <td>{{ number_format($item['stock']) }}</td>
                            <td>{{ number_format($item['monthly_units']) }}</td>
                            <td>{{ $item['last_sale'] }}</td>
                            <td><strong>{{ $item['total_cost'] }}</strong></td>
                            <td>
                                <details class="risk-details">
                                    <summary>View</summary>
                                    <ul>@foreach($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                    <p>{{ $item['recommendation'] }}</p>
                                </details>
                            </td>
                            <td>
                                <details class="promotion-details" @if($item['active_promotion']) open @endif>
                                    <summary>{{ $item['active_promotion'] ? 'Active offer' : 'Choose action' }}</summary>
                                    @if($item['active_promotion'])
                                        <div class="active-promotion">
                                            <strong>{{ $item['active_promotion']['label'] }} · {{ number_format($item['active_promotion']['discount_percent'], 2) }}% off</strong>
                                            <span>₱{{ number_format($item['unit_price'], 2) }} → ₱{{ number_format($item['active_promotion']['promotional_price'], 2) }}</span>
                                            @if($item['active_promotion']['bundle_note'])<small>{{ $item['active_promotion']['bundle_note'] }}</small>@endif
                                            <small>Approved by {{ $item['active_promotion']['administrator'] }}</small>
                                            <form method="POST" action="{{ route('admin.dead-stock.promotions.end', $item['product_id']) }}" data-end-promotion>
                                                @csrf @method('DELETE')
                                                <button class="end-promotion" type="submit">End offer</button>
                                            </form>
                                        </div>
                                    @endif
                                    <form class="promotion-form" method="POST" action="{{ route('admin.dead-stock.promotions.apply', $item['product_id']) }}" data-promotion-form>
                                        @csrf
                                        <input type="hidden" name="action_type" value="discount" data-action-type>
                                        <div class="promotion-actions" role="group" aria-label="Choose promotion for {{ $item['name'] }}">
                                            <button class="selected" type="button" data-promotion-action="discount">Discount</button>
                                            <button type="button" data-promotion-action="promo_bundle">Promo bundle</button>
                                            <button type="button" data-promotion-action="clearance">Clearance sale</button>
                                        </div>
                                        <label>Discount percentage
                                            <span class="discount-input"><input name="discount_percent" type="number" min="1" max="90" step="0.01" value="{{ $item['active_promotion']['discount_percent'] ?? 10 }}" required><b>%</b></span>
                                        </label>
                                        <label data-bundle-field hidden>Bundle details
                                            <input name="bundle_note" type="text" maxlength="160" placeholder="Example: Buy with oil filter">
                                        </label>
                                        <p class="price-preview" data-price-preview data-base-price="{{ $item['unit_price'] }}">Regular ₱{{ number_format($item['unit_price'], 2) }}</p>
                                        <button class="approve-promotion" type="submit">Approve selected action</button>
                                        <small>Nothing changes until you press approve.</small>
                                    </form>
                                </details>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty-table">No products match the selected risk filters.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @if($riskItems->hasPages())
                <nav class="compact-pagination" aria-label="At-risk inventory pages">
                    @if($riskItems->onFirstPage())<span>Previous</span>@else<a href="{{ $riskItems->previousPageUrl() }}">Previous</a>@endif
                    <strong>Page {{ $riskItems->currentPage() }} of {{ $riskItems->lastPage() }}</strong>
                    @if($riskItems->hasMorePages())<a href="{{ $riskItems->nextPageUrl() }}">Next</a>@else<span>Next</span>@endif
                </nav>
            @endif
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
