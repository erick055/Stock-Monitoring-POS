@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','#'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
] : [
    ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','#'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','/staff/compatibility'],
];
$productStoreRoute = $isAdmin ? route('admin.inventory.products.store') : route('staff.inventory.products.store');
$movementStoreRoute = $isAdmin ? route('admin.inventory.movements.store') : route('staff.inventory.movements.store');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Management | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/stock-management.css','resources/js/dashboard.js','resources/js/stock-management.js'])
</head>
<body>
<div class="dashboard-shell stock-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 1 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main stock-main">
        <header class="stock-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div><p class="welcome">INVENTORY CONTROL</p><h1>Stock Management</h1><p>Monitor inventory levels and record every stock movement.</p></div>
            <form class="header-tools" method="GET">
                <label class="search-box"><span>⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Search SKU or product"></label>
                <input type="hidden" name="status" value="{{ $status }}">
                <button class="search-button" type="submit">Search</button>
            </form>
        </header>

        @if(session('success'))<div class="flash-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())
            <div class="flash-message error" role="alert"><strong>Please fix the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <section class="stat-grid stock-stats" aria-label="Stock summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL SKU</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['total_sku']) }}</strong><small>Active products</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TOTAL UNITS</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['total_units']) }}</strong><small>Across all inventory</small></article>
            <article class="stat-card red"><div class="stat-head"><span>CRITICAL LOW</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['critical_low']) }}</strong><small>At or below reorder level</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>STOCK VALUE</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['stock_value'], 2) }}</strong><small>Based on current unit cost</small></article>
        </section>

        <section class="panel inventory-panel">
            <div class="section-heading inventory-heading">
                <div><span class="section-kicker">PRODUCT BALANCES</span><h2>Current Stock Level</h2></div>
                <div class="inventory-actions">
                    <form method="GET" data-filter-form><input type="hidden" name="search" value="{{ $search }}"><select name="status" data-status-filter aria-label="Filter stock status"><option value="all" @selected($status === 'all')>All statuses</option><option value="healthy" @selected($status === 'healthy')>Healthy</option><option value="warning" @selected($status === 'warning')>Low stock</option><option value="critical" @selected($status === 'critical')>Critical</option></select></form>
                    <button class="add-product" type="button" data-open-product>+ Add Product</button>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product ID</th><th>Product</th><th>Category</th><th>Current stock</th><th>Reorder level</th><th>Unit cost</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>#{{ $product->product_id }}</td>
                            <td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></td>
                            <td>{{ $product->category ?: 'Uncategorized' }}</td>
                            <td><div class="stock-level"><span>{{ number_format($product->current_stock) }} units</span><div><i style="width:{{ min(100, $product->reorder_level ? ($product->current_stock / ($product->reorder_level * 3)) * 100 : 100) }}%"></i></div></div></td>
                            <td>{{ number_format($product->reorder_level) }}</td>
                            <td>₱{{ number_format($product->unit_cost, 2) }}</td>
                            <td><span class="status-badge {{ $product->stock_status }}">{{ $product->stock_status === 'warning' ? 'Low stock' : ucfirst($product->stock_status) }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="empty-cell">No products found. Add your first product to begin.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel adjustment-panel">
            <div class="section-heading"><div><span class="section-kicker">QUICK ENTRY</span><h2>Stock Adjustment</h2></div></div>
            <div class="adjustment-grid">
                @foreach([
                    ['in','↓','Stock In','Receive delivered inventory','Record stock in'],
                    ['out','↑','Stock Out','Record released inventory','Record stock out'],
                    ['adjustment','±','Stock Adjustment','Use positive or negative quantity','Save adjustment'],
                ] as [$type,$icon,$title,$help,$button])
                <form class="adjustment-card" method="POST" action="{{ $movementStoreRoute }}">
                    @csrf
                    <input type="hidden" name="movement_type" value="{{ $type }}">
                    <div class="adjustment-title"><span class="movement-icon {{ $type === 'adjustment' ? 'adjust' : $type }}">{{ $icon }}</span><div><strong>{{ $title }}</strong><small>{{ $help }}</small></div></div>
                    <label>Product<select name="product_id" required><option value="">Select product</option>@foreach($allProducts as $product)<option value="{{ $product->product_id }}">{{ $product->sku }} — {{ $product->name }} ({{ $product->current_stock }})</option>@endforeach</select></label>
                    <label>Quantity<input name="quantity" type="number" {{ $type === 'adjustment' ? '' : 'min=1' }} placeholder="{{ $type === 'adjustment' ? 'Example: -2 or 5' : 'Enter quantity' }}" required></label>
                    <label>Reason code<select name="reason_code" required><option value="">Select reason</option>@if($type === 'in')<option value="PURCHASE_RECEIPT">Purchase receipt</option><option value="RETURN_TO_STOCK">Customer return</option>@elseif($type === 'out')<option value="SALE">Sale</option><option value="DAMAGED">Damaged goods</option><option value="SUPPLIER_RETURN">Supplier return</option>@else<option value="PHYSICAL_COUNT">Physical count</option><option value="DATA_CORRECTION">Data correction</option>@endif</select></label>
                    <label>Log / notes<textarea name="logs" rows="2" placeholder="Optional movement details"></textarea></label>
                    <button type="submit" @disabled($allProducts->isEmpty())>{{ $button }}</button>
                </form>
                @endforeach
            </div>
        </section>

        <section class="panel ledger-panel">
            <div class="section-heading"><div><span class="section-kicker">INVENTORY LEDGER</span><h2>Movement Logs</h2></div><small class="ledger-count">Latest {{ $ledgers->count() }} records</small></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Ledger ID</th><th>Product ID</th><th>Product</th><th>Qty In</th><th>Qty Out</th><th>Reason Code</th><th>Logs</th><th>Added By</th><th>Timestamp</th></tr></thead>
                    <tbody>
                    @forelse($ledgers as $ledger)
                        <tr><td>#{{ $ledger->ledger_id }}</td><td>#{{ $ledger->product_id }}</td><td><strong>{{ $ledger->product->name }}</strong><small>{{ $ledger->product->sku }}</small></td><td class="qty-in">{{ number_format($ledger->qty_in) }}</td><td class="qty-out">{{ number_format($ledger->qty_out) }}</td><td><span class="reason-code">{{ str_replace('_', ' ', $ledger->reason_code) }}</span></td><td class="log-cell">{{ $ledger->logs ?: '—' }}</td><td>{{ $ledger->user?->name ?: 'System' }}</td><td>{{ $ledger->created_at->format('M d, Y h:i A') }}</td></tr>
                    @empty
                        <tr><td colspan="9" class="empty-cell">No inventory movements recorded yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>

<div class="stock-modal" data-product-modal data-open-on-error="{{ old('sku') ? 'true' : 'false' }}" hidden>
    <div class="modal-backdrop" data-close-product></div>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="add-product-title">
        <div class="modal-header"><div><span class="section-kicker">NEW INVENTORY ITEM</span><h2 id="add-product-title">Add Product</h2></div><button type="button" data-close-product aria-label="Close">×</button></div>
        <form method="POST" action="{{ $productStoreRoute }}" class="product-form">
            @csrf
            <div class="form-grid">
                <label>SKU<input name="sku" value="{{ old('sku') }}" maxlength="100" required></label>
                <label>Product name<input name="name" value="{{ old('name') }}" maxlength="255" required></label>
                <label>Category<input name="category" value="{{ old('category') }}" maxlength="100" placeholder="e.g. Lubricants"></label>
                <label>Opening Qty In<input name="qty_in" value="{{ old('qty_in', 0) }}" type="number" min="0" required></label>
                <label>Unit cost (₱)<input name="unit_cost" value="{{ old('unit_cost', 0) }}" type="number" min="0" step="0.01" required></label>
                <label>Selling price (₱)<input name="unit_price" value="{{ old('unit_price', 0) }}" type="number" min="0" step="0.01" required></label>
                <label>Reorder level<input name="reorder_level" value="{{ old('reorder_level', 5) }}" type="number" min="0" required></label>
                <label>Reason code<select name="reason_code" required><option value="OPENING_STOCK">Opening stock</option><option value="PURCHASE_RECEIPT">Purchase receipt</option><option value="NEW_PRODUCT">New product</option></select></label>
            </div>
            <label>Inventory log<textarea name="logs" rows="3" maxlength="1000" placeholder="Describe when or why this product was added">{{ old('logs') }}</textarea></label>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-product>Cancel</button><button type="submit" class="primary-button">Add product and ledger entry</button></div>
        </form>
    </section>
</div>
</body>
</html>
