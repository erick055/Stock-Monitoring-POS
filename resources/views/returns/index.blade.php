@php
$isAdmin = $viewRole === 'admin';
$navigation = $isAdmin
    ? [
        ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
        ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
        ['◇','Returns & Damages','#'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
    ]
    : [
        ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
        ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','#'], ['⚙','Part Compatibility','/staff/compatibility'],
    ];
$activeIndex = $isAdmin ? 6 : 4;
$returnRoute = $isAdmin ? route('admin.returns.customer.store') : route('staff.returns.customer.store');
$damageRoute = $isAdmin ? route('admin.returns.damage.store') : route('staff.returns.damage.store');
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
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === $activeIndex ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main returns-main">
        <header class="returns-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">LIVE RETURNS, DAMAGES, AND REFUNDS</p>
                <h1>Return &amp; Damage Management</h1>
                <p>Record customer returns, remove damaged goods from sellable stock, and track replacement status.</p>
            </div>
        </header>

        @if(session('success'))
            <div class="returns-notice success">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="returns-notice error">{{ $errors->first() }}</div>
        @endif

        <section class="stat-grid returns-stats" aria-label="Returns summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="returns-actions-grid">
            <form class="panel return-form-card" method="POST" action="{{ $returnRoute }}">
                @csrf
                <div class="section-heading"><div><span class="section-kicker">CUSTOMER CASE</span><h2>Record Product Return</h2></div></div>
                <div class="form-grid">
                    <label>Product<select name="product_id" required><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->product_id }}">{{ $product->sku }} — {{ $product->name }}</option>@endforeach</select></label>
                    <label>Sale ID optional<input name="sale_id" type="number" min="1" placeholder="Receipt / sale ID"></label>
                    <label>Qty<input name="quantity" type="number" min="1" value="1" required></label>
                    <label>Refund Amount<input name="refund_amount" type="number" step="0.01" min="0" value="0"></label>
                    <label>Condition<select name="item_condition" required><option value="sellable">Sellable</option><option value="damaged">Damaged</option></select></label>
                    <label>Status<select name="status" required><option value="approved">Approved</option><option value="pending">Pending</option><option value="rejected">Rejected</option></select></label>
                    <label class="wide">Reason<input name="reason" maxlength="255" placeholder="Defective, wrong item, customer exchange..." required></label>
                </div>
                <button class="panel-action" type="submit" @disabled($products->isEmpty())>+ Save Product Return</button>
            </form>

            <form class="panel return-form-card" method="POST" action="{{ $damageRoute }}">
                @csrf
                <div class="section-heading"><div><span class="section-kicker">DAMAGE TRACKER</span><h2>Record Damaged Goods</h2></div></div>
                <div class="form-grid">
                    <label>Product<select name="product_id" required><option value="">Select product</option>@foreach($products as $product)<option value="{{ $product->product_id }}">{{ $product->sku }} — {{ $product->name }} ({{ $product->current_stock }})</option>@endforeach</select></label>
                    <label>Qty<input name="quantity" type="number" min="1" value="1" required></label>
                    <label>Replacement<select name="replacement_status" required><option value="pending">Pending</option><option value="ordered">Ordered</option><option value="replaced">Replaced</option><option value="not_replaceable">Not replaceable</option></select></label>
                    <label>Status<select name="status" required><option value="reported">Reported</option><option value="reviewed">Reviewed</option><option value="disposed">Disposed</option></select></label>
                    <label class="wide">Damage Reason<input name="damage_reason" maxlength="255" placeholder="Water damaged, broken packaging, shop damage..." required></label>
                </div>
                <button class="panel-action" type="submit" @disabled($products->isEmpty())>+ Save Damage Log</button>
            </form>
        </section>

        <section class="panel returns-panel">
            <div class="section-heading"><div><span class="section-kicker">CUSTOMER CASES</span><h2>Customer Returns</h2></div></div>
            <div class="case-list">
                @forelse($customerReturns as $return)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $return->product?->name ?? 'Deleted product' }}</strong>
                            <small>Return #{{ $return->return_id }} @if($return->sale_id) | Sale #{{ $return->sale_id }} @endif | {{ $return->returned_at->format('M d, Y h:i A') }}</small>
                            <div class="case-meta"><span>Qty: {{ $return->quantity }}</span><span>Reason: {{ $return->reason }}</span><span>Condition: {{ ucfirst($return->item_condition) }}</span></div>
                        </div>
                        <div class="case-side">
                            <strong class="amount">₱{{ number_format($return->refund_amount, 2) }}</strong>
                            <span class="status {{ $return->status === 'approved' ? 'approved' : 'pending' }}">{{ ucfirst($return->status) }}</span>
                            <small>{{ $return->user?->name ?? 'System' }}</small>
                        </div>
                    </article>
                @empty
                    <div class="empty-returns">No customer returns recorded yet.</div>
                @endforelse
            </div>
        </section>

        <section class="panel returns-panel">
            <div class="section-heading"><div><span class="section-kicker">INTERNAL DAMAGE TRACKER</span><h2>Damage Log</h2></div></div>
            <div class="case-list">
                @forelse($damageLogs as $log)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $log->product?->name ?? 'Deleted product' }}</strong>
                            <small>{{ $log->damage_reason }}</small>
                            <div class="case-meta"><span>Replacement Status: {{ ucfirst(str_replace('_', ' ', $log->replacement_status)) }}</span><span>Logged by: {{ $log->user?->name ?? 'System' }}</span></div>
                        </div>
                        <div class="case-side">
                            <strong>{{ $log->quantity }} Units</strong>
                            <small>{{ $log->reported_at->format('M d, Y') }}</small>
                            <span class="status pending">{{ ucfirst($log->status) }}</span>
                        </div>
                    </article>
                @empty
                    <div class="empty-returns">No damaged goods logged yet.</div>
                @endforelse
            </div>
        </section>

        <div class="returns-toast" data-returns-toast hidden role="status">Return and damage action saved.</div>
    </main>
</div>
</body>
</html>
