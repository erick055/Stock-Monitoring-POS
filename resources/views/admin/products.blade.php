@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
         ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'],['@','Dead Stock', '/admin/deadstock'],
        ['◇','Returns & Damages','/admin/returns'], ['♙','User Management','/admin/users'], ['⚙','Settings','/admin/settings'],
];
$products = [
    ['ENG-OIL-1L','Engine Oil 1L','Lubricants','₱180','₱250',42,'28%','Active'],
    ['BRK-PAD-01','Brake Pad Set','Brakes','₱590','₱850',6,'31%','Low stock'],
    ['OIL-FLT-02','Oil Filter','Filters','₱115','₱180',18,'36%','Active'],
    ['CHAIN-428','Drive Chain 428','Drivetrain','₱850','₱1,200',28,'29%','Active'],
    ['TIRE-F-17','Front Tire 90/80-17','Tires','₱890','₱1,200',12,'26%','Active'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/products.css','resources/js/dashboard.js','resources/js/products.js'])
</head>
<body>
<div class="dashboard-shell products-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 2 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">↪</button></form>
        </div>
    </aside>

    <main class="dashboard-main products-main">
        <header class="products-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">☰</button>
            <div><p class="welcome">CATALOG MANAGEMENT</p><h1>Products</h1><p>Manage inventory products, categories, and pricing.</p></div>
            <button class="primary-action" type="button" data-open-product>+ Add Product</button>
        </header>

        <section class="stat-grid product-stats" aria-label="Product summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL PRODUCTS</span><span class="trend-dot"></span></div><strong>156</strong><small>12 added this month</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>CATEGORIES</span><span class="trend-dot"></span></div><strong>8</strong><small>Active categories</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>AVERAGE MARGIN</span><span class="trend-dot"></span></div><strong>25%</strong><small>+2.4% from last month</small></article>
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL VALUE</span><span class="trend-dot"></span></div><strong>₱234K</strong><small>Current inventory value</small></article>
        </section>

        <section class="panel products-panel">
            <div class="products-toolbar">
                <div><span class="section-kicker">PRODUCT CATALOG</span><h2>Products Inventory</h2></div>
                <div class="toolbar-controls">
                    <label class="product-search"><span>⌕</span><input type="search" placeholder="Search name or SKU" data-product-search></label>
                    <select data-category-filter aria-label="Filter by category"><option value="all">All categories</option><option>Lubricants</option><option>Brakes</option><option>Filters</option><option>Drivetrain</option><option>Tires</option></select>
                </div>
            </div>
            <div class="products-table-wrap">
                <table>
                    <thead><tr><th>Product</th><th>Category</th><th>Unit cost</th><th>Selling price</th><th>Stock</th><th>Margin</th><th>Status</th><th></th></tr></thead>
                    <tbody data-product-rows>
                    @foreach($products as $product)
                        <tr data-category="{{ strtolower($product[2]) }}" data-search="{{ strtolower($product[0].' '.$product[1]) }}">
                            <td><div class="product-cell"><span class="product-thumb">{{ strtoupper(substr($product[1],0,1)) }}</span><div><strong>{{ $product[1] }}</strong><small>{{ $product[0] }}</small></div></div></td>
                            <td>{{ $product[2] }}</td><td>{{ $product[3] }}</td><td><strong>{{ $product[4] }}</strong></td><td>{{ $product[5] }} units</td><td><span class="margin-badge">{{ $product[6] }}</span></td><td><span class="product-status {{ $product[7] === 'Active' ? 'active' : 'low' }}">{{ $product[7] }}</span></td><td><button class="row-action" type="button">•••</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                <div class="empty-products" data-empty-products hidden><span>⌕</span><strong>No products found</strong><small>Try another name, SKU, or category.</small></div>
            </div>
            <footer class="table-footer"><span>Showing 5 of 156 products</span><div><button type="button" disabled>←</button><button class="current" type="button">1</button><button type="button">2</button><button type="button">3</button><button type="button">→</button></div></footer>
        </section>
    </main>
</div>

<div class="modal-backdrop" data-product-modal hidden>
    <section class="product-modal" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
        <header><div><span class="section-kicker">NEW CATALOG ITEM</span><h2 id="product-modal-title">Add Product</h2></div><button type="button" data-close-product aria-label="Close">×</button></header>
        <form data-product-form>
            <div class="form-grid"><label>Product name<input name="name" placeholder="e.g. Engine Oil 1L" required></label><label>SKU<input name="sku" placeholder="e.g. ENG-OIL-1L" required></label><label>Category<select name="category" required><option value="">Select category</option><option>Lubricants</option><option>Brakes</option><option>Filters</option><option>Drivetrain</option><option>Tires</option></select></label><label>Initial stock<input type="number" name="stock" min="0" placeholder="0" required></label><label>Unit cost<input type="number" name="cost" min="0" step="0.01" placeholder="0.00" required></label><label>Selling price<input type="number" name="price" min="0" step="0.01" placeholder="0.00" required></label></div>
            <label class="full-field">Description<textarea name="description" rows="3" placeholder="Optional product details"></textarea></label>
            <footer><button class="secondary-button" type="button" data-close-product>Cancel</button><button class="save-button" type="submit">Save Product</button></footer>
        </form>
    </section>
</div>
<div class="product-toast" data-product-toast hidden role="status">Product saved in UI preview.</div>
</body>
</html>