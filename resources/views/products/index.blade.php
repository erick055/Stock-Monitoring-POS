@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
] : [
    ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','/staff/compatibility'],
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
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 2 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main products-main">
        <header class="products-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div><p class="welcome">READ-ONLY CATALOG</p><h1>Products</h1><p>View product information, pricing, and current inventory balances.</p></div>
            <span class="read-only-badge">View only</span>
        </header>

        <section class="stat-grid product-stats" aria-label="Product summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL PRODUCTS</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['total_products']) }}</strong><small>Active catalog items</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>CATEGORIES</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['categories']) }}</strong><small>Active categories</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>AVERAGE MARGIN</span><span class="trend-dot"></span></div><strong>{{ number_format($summary['average_margin'], 1) }}%</strong><small>Based on selling price</small></article>
            <article class="stat-card purple"><div class="stat-head"><span>STOCK VALUE</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['total_value'], 2) }}</strong><small>At current unit cost</small></article>
        </section>

        <section class="panel products-panel">
            <div class="products-toolbar">
                <div><span class="section-kicker">PRODUCT CATALOG</span><h2>Products Inventory</h2></div>
                <form class="toolbar-controls" method="GET" data-products-filter>
                    <label class="product-search"><span>⌕</span><input name="search" value="{{ $search }}" type="search" placeholder="Search name, SKU, category"></label>
                    <select name="category" aria-label="Filter by category" data-auto-submit><option value="">All categories</option>@foreach($categories as $item)<option value="{{ $item }}" @selected($category === $item)>{{ $item }}</option>@endforeach</select>
                    <select name="sort" aria-label="Sort products" data-auto-submit><option value="name" @selected($sort === 'name')>Name A–Z</option><option value="newest" @selected($sort === 'newest')>Newest</option><option value="stock_high" @selected($sort === 'stock_high')>Stock: high to low</option><option value="stock_low" @selected($sort === 'stock_low')>Stock: low to high</option><option value="price_high" @selected($sort === 'price_high')>Price: high to low</option><option value="price_low" @selected($sort === 'price_low')>Price: low to high</option></select>
                    <button class="filter-button" type="submit">Search</button>
                    @if($search || $category || $sort !== 'name')<a class="clear-filter" href="{{ request()->url() }}">Clear</a>@endif
                </form>
            </div>
            <div class="products-table-wrap">
                <table>
                    <thead><tr><th>Product ID</th><th>Product</th><th>Category</th><th>Unit cost</th><th>Selling price</th><th>Stock</th><th>Margin</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        @php
                            $margin = (float) $product->unit_price > 0 ? (((float) $product->unit_price - (float) $product->unit_cost) / (float) $product->unit_price) * 100 : 0;
                            $status = $product->stock_status;
                            $detailData = [
                                'id' => $product->product_id,
                                'sku' => $product->sku,
                                'name' => $product->name,
                                'category' => $product->category ?: 'Uncategorized',
                                'unitCost' => number_format($product->unit_cost, 2),
                                'unitPrice' => number_format($product->unit_price, 2),
                                'stock' => number_format($product->current_stock),
                                'reorder' => number_format($product->reorder_level),
                                'margin' => number_format($margin, 1),
                                'status' => $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical'),
                                'created' => $product->created_at->format('M d, Y h:i A'),
                                'updated' => $product->updated_at->format('M d, Y h:i A'),
                            ];
                        @endphp
                        <tr>
                            <td>#{{ $product->product_id }}</td>
                            <td><div class="product-cell"><span class="product-thumb">{{ strtoupper(substr($product->name, 0, 1)) }}</span><div><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></div></div></td>
                            <td>{{ $product->category ?: 'Uncategorized' }}</td>
                            <td>₱{{ number_format($product->unit_cost, 2) }}</td>
                            <td><strong>₱{{ number_format($product->unit_price, 2) }}</strong></td>
                            <td>{{ number_format($product->current_stock) }} units</td>
                            <td><span class="margin-badge">{{ number_format($margin, 1) }}%</span></td>
                            <td><span class="product-status {{ $status === 'healthy' ? 'active' : 'low' }}">{{ $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical') }}</span></td>
                            <td><button class="view-product" type="button" data-view-product data-product="{{ e(json_encode($detailData)) }}">View</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="9"><div class="empty-products"><span>⌕</span><strong>No products found</strong><small>Try another name, SKU, category, or filter.</small></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <footer class="table-footer">
                <span>Showing {{ $products->firstItem() ?? 0 }}–{{ $products->lastItem() ?? 0 }} of {{ $products->total() }} products</span>
                @if($products->hasPages())<div class="pagination">{{ $products->onEachSide(1)->links('products.pagination') }}</div>@endif
            </footer>
        </section>
    </main>
</div>

<div class="product-details-backdrop" data-product-details hidden>
    <div class="details-shade" data-close-details></div>
    <section class="product-details" role="dialog" aria-modal="true" aria-labelledby="product-details-title">
        <header><div><span class="section-kicker">READ-ONLY PRODUCT RECORD</span><h2 id="product-details-title" data-detail-name>Product Details</h2></div><button type="button" data-close-details aria-label="Close">×</button></header>
        <div class="details-grid">
            <div><small>Product ID</small><strong data-detail="id"></strong></div><div><small>SKU</small><strong data-detail="sku"></strong></div>
            <div><small>Category</small><strong data-detail="category"></strong></div><div><small>Status</small><strong data-detail="status"></strong></div>
            <div><small>Unit Cost</small><strong data-detail="unitCost"></strong></div><div><small>Selling Price</small><strong data-detail="unitPrice"></strong></div>
            <div><small>Current Stock</small><strong data-detail="stock"></strong></div><div><small>Reorder Level</small><strong data-detail="reorder"></strong></div>
            <div><small>Margin</small><strong data-detail="margin"></strong></div><div><small>Created</small><strong data-detail="created"></strong></div>
            <div class="wide"><small>Last Updated</small><strong data-detail="updated"></strong></div>
        </div>
        <footer><span>This page does not allow product changes.</span><button type="button" data-close-details>Close</button></footer>
    </section>
</div>
</body>
</html>
