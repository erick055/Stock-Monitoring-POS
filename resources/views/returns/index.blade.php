@php
$isAdmin = $viewRole === 'admin';
$navigation = $isAdmin
    ? [
        ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
        ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
        ['arrow-repeat','Returns & Damages','#'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
    ]
    : [
        ['house-door','Dashboard','/staff/dashboard'], ['box-seam','Products','/staff/products'],
        ['cart3','POS Checkout','/staff/pos'], ['arrow-repeat','Return & Damage','#'], ['gear','Part Compatibility','/staff/compatibility'],
    ];
$activeIndex = $isAdmin ? 7 : 3;
$returnRoute = $isAdmin ? route('admin.returns.customer.store') : route('staff.returns.customer.store');
$damageRoute = $isAdmin ? route('admin.returns.damage.store') : route('staff.returns.damage.store');
$oldReceipt = $receipts->firstWhere('id', (int) old('sale_id'));
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Return & Damage | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/returns.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/returns.js'])
</head>
<body>
<div class="dashboard-shell returns-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === $activeIndex ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main returns-main">
        <header class="returns-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div>
                <p class="welcome">LIVE RETURNS, DAMAGES, AND REFUNDS</p>
                <h1>Return &amp; Damage Management</h1>
                <p>Find the customer receipt first, then select the exact item that was sold.</p>
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
            <form class="panel return-form-card" method="POST" action="{{ $returnRoute }}" data-receipt-form data-form-kind="return">
                @csrf
                <div class="section-heading"><div><span class="section-kicker">CUSTOMER CASE</span><h2>Record Product Return</h2></div></div>
                <div class="form-grid">
                    <label class="wide">Customer Receipt
                        <input type="search" list="customer-receipts" value="{{ $oldReceipt['label'] ?? '' }}" placeholder="Search receipt number, date, amount, or item" autocomplete="off" required data-receipt-search>
                        <input type="hidden" name="sale_id" value="{{ old('sale_id') }}" data-receipt-id>
                    </label>
                    <div class="receipt-preview wide" data-receipt-preview hidden></div>
                    <label class="wide">Product From This Receipt
                        <select name="product_id" required disabled data-receipt-product data-old-value="{{ old('product_id') }}"><option value="">Select a receipt first</option></select>
                    </label>
                    <div class="refund-value-card wide" data-refund-value hidden aria-live="polite"></div>
                    <label>Qty<input name="quantity" type="number" min="1" value="{{ old('quantity', 1) }}" required data-item-quantity></label>
                    <label>{{ $viewRole === 'admin' ? 'Refund Amount' : 'Requested Refund Amount' }}<input name="refund_amount" type="number" step="0.01" min="0" value="{{ old('refund_amount', 0) }}" data-refund-amount></label>
                    <label>Condition<select name="item_condition" required><option value="sellable" @selected(old('item_condition') === 'sellable')>Sellable</option><option value="damaged" @selected(old('item_condition') === 'damaged')>Damaged</option></select></label>
                    @if($viewRole === 'admin')
                        <label>Status<select name="status" required><option value="approved" @selected(old('status') === 'approved')>Approved</option><option value="pending" @selected(old('status') === 'pending')>Pending</option><option value="rejected" @selected(old('status') === 'rejected')>Rejected</option></select></label>
                    @else
                        <input type="hidden" name="status" value="pending">
                        <label>Status<span class="inventory-note">Pending owner approval</span></label>
                    @endif
                    <label class="wide">Reason<input name="reason" maxlength="255" value="{{ old('reason') }}" placeholder="Defective, wrong item, customer exchange..." required></label>
                </div>
                @if($viewRole !== 'admin')<p class="inventory-note">You can submit a return from any cashier's receipt. The owner must approve the requested refund and stock restoration.</p>@endif
                <button class="panel-action" type="submit" @disabled($receipts->isEmpty())><i class="bi bi-check2" aria-hidden="true"></i> {{ $viewRole === 'admin' ? 'Save Product Return' : 'Submit for owner review' }}</button>
            </form>

            <form class="panel return-form-card" method="POST" action="{{ $damageRoute }}" data-receipt-form data-form-kind="damage">
                @csrf
                <div class="section-heading"><div><span class="section-kicker">CUSTOMER DAMAGE TRACKER</span><h2>Record Damaged Receipt Item</h2></div></div>
                <div class="form-grid">
                    <label class="wide">Customer Receipt
                        <input type="search" list="customer-receipts" value="{{ $oldReceipt['label'] ?? '' }}" placeholder="Search receipt number, date, amount, or item" autocomplete="off" required data-receipt-search>
                        <input type="hidden" name="sale_id" value="{{ old('sale_id') }}" data-receipt-id>
                    </label>
                    <div class="receipt-preview wide" data-receipt-preview hidden></div>
                    <label class="wide">Product From This Receipt
                        <select name="product_id" required disabled data-receipt-product data-old-value="{{ old('product_id') }}"><option value="">Select a receipt first</option></select>
                    </label>
                    <div class="refund-value-card wide" data-refund-value hidden aria-live="polite"></div>
                    <label>Qty<input name="quantity" type="number" min="1" value="{{ old('quantity', 1) }}" required data-item-quantity></label>
                    <label>Replacement<select name="replacement_status" required><option value="pending" @selected(old('replacement_status') === 'pending')>Pending</option><option value="ordered" @selected(old('replacement_status') === 'ordered')>Ordered</option><option value="replaced" @selected(old('replacement_status') === 'replaced')>Replaced</option><option value="not_replaceable" @selected(old('replacement_status') === 'not_replaceable')>Not replaceable</option></select></label>
                    @if($viewRole === 'admin')
                        <label>Status<select name="status" required><option value="reported" @selected(old('status') === 'reported')>Reported</option><option value="reviewed" @selected(old('status') === 'reviewed')>Reviewed</option><option value="disposed" @selected(old('status') === 'disposed')>Disposed</option></select></label>
                    @else
                        <input type="hidden" name="status" value="reported"><label>Status<span class="inventory-note">Pending owner acceptance</span></label>
                    @endif
                    <label class="wide">Damage Reason<input name="damage_reason" maxlength="255" value="{{ old('damage_reason') }}" placeholder="Broken, defective, or damaged after purchase..." required></label>
                </div>
                <p class="inventory-note">This documents an item already sold on the receipt. Current inventory will not be deducted again.</p>
                @if($viewRole !== 'admin')<p class="inventory-note">The owner must accept this report before it appears in the Damage Log.</p>@endif
                <button class="panel-action" type="submit" @disabled($receipts->isEmpty())>{{ $viewRole === 'admin' ? 'Save Damage Log' : 'Submit damage for review' }}</button>
            </form>
        </section>

        @if($viewRole === 'admin' && $pendingDamages->isNotEmpty())
            <section class="panel returns-panel">
                <div class="section-heading"><div><span class="section-kicker">OWNER REVIEW</span><h2>Pending Damage Reports</h2></div></div>
                <div class="case-list">
                    @foreach($pendingDamages as $damage)
                        <article class="case-card"><div class="case-main"><strong>{{ $damage->product?->name }}</strong><small>Report #{{ $damage->damage_id }} · Receipt #{{ $damage->sale_id }} · Submitted by {{ $damage->user?->name }}</small><div class="case-meta"><span>{{ $damage->quantity }} units</span><span>{{ $damage->damage_reason }}</span><span>Replacement: {{ ucfirst($damage->replacement_status) }}</span></div></div>
                            <form class="return-review-actions" method="POST" action="{{ route('admin.returns.damage.review', $damage) }}">@csrf @method('PATCH')<button name="decision" value="accepted" type="submit"><i class="bi bi-check-lg" aria-hidden="true"></i> Accept</button><button name="decision" value="rejected" type="submit"><i class="bi bi-x-lg" aria-hidden="true"></i> Reject</button></form>
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
        <datalist id="customer-receipts">
            @foreach($receipts as $receipt)
                <option value="{{ $receipt['label'] }}">{{ $receipt['cashier'] }} · {{ $receipt['items']->count() }} available item(s)</option>
            @endforeach
        </datalist>
        <script type="application/json" id="receipt-selector-data">{!! json_encode($receipts, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) !!}</script>

        <section class="panel returns-panel">
            <div class="section-heading"><div><span class="section-kicker">CUSTOMER CASES</span><h2>Customer Returns</h2></div></div>
            <div class="case-list">
                @forelse($customerReturns as $return)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $return->product?->name ?? 'Deleted product' }}</strong>
                            <small>Return #{{ $return->return_id }} @if($return->sale_id) | Receipt POS-{{ str_pad((string) $return->sale_id, 6, '0', STR_PAD_LEFT) }} @endif | {{ $return->returned_at->format('M d, Y h:i A') }}</small>
                            <div class="case-meta"><span>Qty: {{ $return->quantity }}</span><span>Reason: {{ $return->reason }}</span><span>Condition: {{ ucfirst($return->item_condition) }}</span></div>
                        </div>
                        <div class="case-side">
                            <strong class="amount">₱{{ number_format($return->refund_amount, 2) }}</strong>
                            @if($return->status === 'pending')<small>Requested refund · not approved</small>@endif
                            <span class="status {{ $return->status === 'approved' ? 'approved' : 'pending' }}">{{ ucfirst($return->status) }}</span>
                            <small>{{ $return->user?->name ?? 'System' }}</small>
                            @if($viewRole === 'admin' && $return->status === 'pending')
                                <form class="return-review-actions" method="POST" action="{{ route('admin.returns.customer.review', $return) }}">
                                    @csrf @method('PATCH')
                                    <button type="submit" name="decision" value="approved" aria-label="Approve return {{ $return->return_id }}"><i class="bi bi-check-lg" aria-hidden="true"></i> Approve</button>
                                    <button type="submit" name="decision" value="rejected" aria-label="Reject return {{ $return->return_id }}"><i class="bi bi-x-lg" aria-hidden="true"></i> Reject</button>
                                </form>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="empty-returns">No customer returns recorded yet.</div>
                @endforelse
            </div>
        </section>

        <section class="panel returns-panel">
            <div class="section-heading"><div><span class="section-kicker">CUSTOMER DAMAGE TRACKER</span><h2>Damage Log</h2></div></div>
            <div class="case-list">
                @forelse($damageLogs as $log)
                    <article class="case-card">
                        <div class="case-main">
                            <strong>{{ $log->product?->name ?? 'Deleted product' }}</strong>
                            <small>@if($log->sale_id)Receipt POS-{{ str_pad((string) $log->sale_id, 6, '0', STR_PAD_LEFT) }} · @endif{{ $log->damage_reason }}</small>
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
@include('partials.login-stock-alert')
</body>
</html>
