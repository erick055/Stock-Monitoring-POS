@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','#'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '/admin/deadstock'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','User Management','/admin/users'], ['⚙','Settings','/admin/settings'],
];
$products = [
    ['ENG-OIL-1L','Engine Oil 1L','Lubricants',42,12,'₱250','healthy'],
    ['BRK-PAD-01','Brake Pad Set','Brakes',6,10,'₱850','critical'],
    ['OIL-FLT-02','Oil Filter','Filters',18,8,'₱180','warning'],
    ['CHAIN-428','Drive Chain 428','Drivetrain',28,10,'₱1,200','healthy'],
];
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
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 1 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">↪</button></form>
        </div>
    </aside>

    <main class="dashboard-main stock-main">
        <header class="stock-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">☰</button>
            <div><p class="welcome">INVENTORY CONTROL</p><h1>Stock Management</h1><p>Monitor inventory levels and record stock movement.</p></div>
            <div class="header-tools"><label class="search-box"><span>⌕</span><input type="search" placeholder="Search SKU or product" data-stock-search></label><button class="more-button" type="button">•••</button></div>
        </header>

        <section class="stat-grid stock-stats" aria-label="Stock summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL SKU</span><span class="trend-dot"></span></div><strong>156</strong><small>Active products</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>TOTAL UNITS</span><span class="trend-dot"></span></div><strong>3,456</strong><small>Across all locations</small></article>
            <article class="stat-card red"><div class="stat-head"><span>CRITICAL LOW</span><span class="trend-dot"></span></div><strong>6</strong><small>Needs immediate action</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>STOCK VALUE</span><span class="trend-dot"></span></div><strong>₱234K</strong><small>At current unit cost</small></article>
        </section>

        <section class="panel inventory-panel">
            <div class="section-heading inventory-heading"><div><span class="section-kicker">INVENTORY LEDGER</span><h2>Current Stock Level</h2></div><div class="inventory-actions"><select data-status-filter aria-label="Filter stock status"><option value="all">All statuses</option><option value="healthy">Healthy</option><option value="warning">Low stock</option><option value="critical">Critical</option></select><button class="add-product" type="button">+ Add Product</button></div></div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Category</th><th>Current stock</th><th>Reorder level</th><th>Unit price</th><th>Status</th><th></th></tr></thead>
                    <tbody data-stock-rows>
                    @foreach($products as $product)
                        <tr data-status="{{ $product[6] }}" data-search="{{ strtolower($product[0].' '.$product[1]) }}">
                            <td><strong>{{ $product[1] }}</strong><small>{{ $product[0] }}</small></td><td>{{ $product[2] }}</td><td><div class="stock-level"><span>{{ $product[3] }} units</span><div><i style="width:{{ min(100,$product[3]*2) }}%"></i></div></div></td><td>{{ $product[4] }}</td><td>{{ $product[5] }}</td><td><span class="status-badge {{ $product[6] }}">{{ ucfirst($product[6]) }}</span></td><td><button class="row-menu" type="button">•••</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <p class="empty-state" data-empty-state hidden>No matching products found.</p>
            </div>
        </section>

        <section class="panel adjustment-panel">
            <div class="section-heading"><div><span class="section-kicker">QUICK ENTRY</span><h2>Stock Adjustment</h2></div><span class="ui-note">UI preview</span></div>
            <div class="adjustment-grid">
                <form class="adjustment-card" data-demo-form><div class="adjustment-title"><span class="movement-icon in">↓</span><div><strong>Stock In</strong><small>Receive delivered inventory</small></div></div><label>Quantity<input type="number" min="1" placeholder="Enter quantity" required></label><label>Product<select required><option value="">Select product</option>@foreach($products as $product)<option>{{ $product[1] }}</option>@endforeach</select></label><button type="submit">Record stock in</button></form>
                <form class="adjustment-card" data-demo-form><div class="adjustment-title"><span class="movement-icon out">↑</span><div><strong>Stock Out</strong><small>Record released inventory</small></div></div><label>Quantity<input type="number" min="1" placeholder="Enter quantity" required></label><label>Product<select required><option value="">Select product</option>@foreach($products as $product)<option>{{ $product[1] }}</option>@endforeach</select></label><button type="submit">Record stock out</button></form>
                <form class="adjustment-card" data-demo-form><div class="adjustment-title"><span class="movement-icon adjust">±</span><div><strong>Stock Adjustment</strong><small>Correct inventory variance</small></div></div><label>Quantity<input type="number" placeholder="Enter adjustment" required></label><label>Product<select required><option value="">Select product</option>@foreach($products as $product)<option>{{ $product[1] }}</option>@endforeach</select></label><button type="submit">Save adjustment</button></form>
            </div>
        </section>
        <div class="demo-toast" data-demo-toast role="status" hidden>Stock entry saved in UI preview.</div>
    </main>
</div>
</body>
</html>