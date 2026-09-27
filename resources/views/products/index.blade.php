@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'], ['♟','Account Management','/admin/accounts'],
] : [
    ['⌂','Dashboard','/staff/dashboard'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','/staff/compatibility'],
];
$activeIndex = $isAdmin ? 2 : 1;
$productDetailRecords = [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/products.css','resources/css/sorting-controls.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/products.js'])
</head>
<body>
<div class="dashboard-shell products-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === $activeIndex ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
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
            <article class="stat-card cyan"><div class="stat-head"><span>AVERAGE PROFIT</span><span class="trend-dot"></span></div><strong>{{ $summary['average_profit'] < 0 ? '-₱' : '₱' }}{{ number_format(abs($summary['average_profit']), 0) }}</strong><small>Profit per unit</small></article>
            <article class="stat-card purple"><div class="stat-head"><span>STOCK VALUE</span><span class="trend-dot"></span></div><strong>₱{{ number_format($summary['total_value'], 2) }}</strong><small>At current unit cost</small></article>
        </section>

        <section class="panel products-panel">
            <div class="products-toolbar">
                <div><span class="section-kicker">PRODUCT CATALOG</span><h2>Products Inventory</h2></div>
                <form class="toolbar-controls" method="GET" data-products-filter>
                    <label class="product-search"><span>⌕</span><input name="search" value="{{ $search }}" type="search" placeholder="Search name, SKU, category, shelf"></label>
                    <select name="category" aria-label="Filter by category" data-auto-submit><option value="">All categories</option>@foreach($categories as $item)<option value="{{ $item }}" @selected($category === $item)>{{ $item }}</option>@endforeach</select>
                    <select name="sort" aria-label="Sort products"><option value="name" @selected($sort === 'name')>Name A–Z</option><option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option><option value="newest" @selected($sort === 'newest')>Newest added</option><option value="oldest" @selected($sort === 'oldest')>Oldest added</option><option value="stock_high" @selected($sort === 'stock_high')>Stock: high to low</option><option value="stock_low" @selected($sort === 'stock_low')>Stock: low to high</option><option value="price_high" @selected($sort === 'price_high')>Price: high to low</option><option value="price_low" @selected($sort === 'price_low')>Price: low to high</option></select>
                    <button class="sort-button" type="submit">Sort</button>
                    <button class="filter-button" type="submit">Search</button>
                    @if($search || $category || $sort !== 'name')<a class="clear-filter" href="{{ request()->url() }}">Clear</a>@endif
                </form>
            </div>
            <div class="products-table-wrap">
                <table>
                    <thead><tr><th>Product ID</th><th>Product</th><th>Category</th><th>Shelf location</th><th>Unit cost</th><th>Selling price</th><th>Stock</th><th>Profit</th><th>Status</th><th>Details</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        @php
                            $grossProfit = (float) $product->unit_price - (float) $product->unit_cost;
                            $status = $product->stock_status;
                            $promotion = $product->activePromotion;
                            $promotionText = $promotion
                                ? $promotion->action_label.' · '.($promotion->action_type === 'promo_bundle'
                                    ? ($promotion->bundleProduct ? 'Free '.$promotion->bundleProduct->name : ($promotion->bundle_note ?: 'Bundle offer'))
                                    : number_format((float) $promotion->discount_percent, 1).'% off · ₱'.number_format((float) $promotion->promotional_price, 2))
                                : 'No active promotion';
                            $supplierPriceText = $product->supplierPrices->isEmpty()
                                ? 'No matched supplier prices'
                                : $product->supplierPrices->sortBy('unit_price')->map(function ($price) {
                                    $availability = $price->available_quantity === null ? 'stock unknown' : number_format($price->available_quantity).' available';
                                    $updated = $price->last_updated_at?->format('M d, Y') ?? 'date unavailable';
                                    return ($price->supplier?->name ?? 'Deleted supplier').' · '.$price->currency.' '.number_format((float) $price->unit_price, 2).' · '.$availability.' · updated '.$updated;
                                })->join("\n");
                            $latestLedger = $product->latestLedger;
                            $latestMovement = $latestLedger
                                ? (($latestLedger->qty_in > 0 ? '+'.number_format($latestLedger->qty_in) : '-'.number_format($latestLedger->qty_out)).' units · '.str_replace('_', ' ', $latestLedger->reason_code).' · '.$latestLedger->created_at->format('M d, Y h:i A').' · '.($latestLedger->user?->name ?? 'System'))
                                : 'No inventory movements recorded';
                            $detailData = [
                                'id' => $product->product_id,
                                'sku' => $product->sku,
                                'name' => $product->name,
                                'category' => $product->category ?: 'Uncategorized',
                                'shelfLocation' => $product->shelf_location ?: 'Not assigned',
                                'manufacturer' => $product->manufacturer ?: 'Not provided',
                                'manufacturerPartNumber' => $product->manufacturer_part_number ?: 'Not provided',
                                'description' => $product->description ?: 'No description provided',
                                'catalogStatus' => $product->is_active ? 'Active' : 'Inactive',
                                'unitCost' => number_format($product->unit_cost, 2),
                                'unitPrice' => number_format($product->unit_price, 2),
                                'grossProfit' => ($grossProfit < 0 ? '-₱' : '₱').number_format(abs($grossProfit), 0),
                                'stock' => number_format($product->current_stock),
                                'reorder' => number_format($product->reorder_level),
                                'inventoryValue' => number_format($product->current_stock * (float) $product->unit_cost, 2),
                                'status' => $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical'),
                                'unitsSold' => number_format((int) ($product->units_sold ?? 0)),
                                'salesRevenue' => number_format((float) ($product->sales_revenue ?? 0), 2),
                                'returnedUnits' => number_format((int) ($product->returned_units ?? 0)),
                                'damagedUnits' => number_format((int) ($product->damaged_units ?? 0)),
                                'promotion' => $promotionText,
                                'supplierCount' => number_format($product->supplier_prices_count),
                                'supplierPrices' => $supplierPriceText,
                                'movementCount' => number_format($product->ledgers_count),
                                'latestMovement' => $latestMovement,
                                'created' => $product->created_at->format('M d, Y h:i A'),
                                'updated' => $product->updated_at->format('M d, Y h:i A'),
                            ];
                            $productDetailRecords[] = $detailData;
                        @endphp
                        <tr>
                            <td>#{{ $product->product_id }}</td>
                            <td><div class="product-cell"><span class="product-thumb">{{ strtoupper(substr($product->name, 0, 1)) }}</span><div><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></div></div></td>
                            <td>{{ $product->category ?: 'Uncategorized' }}</td>
                            <td><span class="shelf-location">{{ $product->shelf_location ?: 'Not assigned' }}</span></td>
                            <td>₱{{ number_format($product->unit_cost, 2) }}</td>
                            <td><strong>₱{{ number_format($product->unit_price, 2) }}</strong></td>
                            <td>{{ number_format($product->current_stock) }} units</td>
                            <td><span class="profit-badge">{{ $grossProfit < 0 ? '-₱' : '₱' }}{{ number_format(abs($grossProfit), 0) }}</span></td>
                            <td><span class="product-status {{ $status === 'healthy' ? 'active' : 'low' }}">{{ $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical') }}</span></td>
                            <td><button class="view-product" type="button" popovertarget="product-details-{{ $product->product_id }}">View</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="10"><div class="empty-products"><span>⌕</span><strong>No products found</strong><small>Try another name, SKU, category, shelf, or filter.</small></div></td></tr>
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

@foreach($productDetailRecords as $detail)
    <section class="product-details" id="product-details-{{ $detail['id'] }}" popover aria-labelledby="product-details-title-{{ $detail['id'] }}">
        <header><div><span class="section-kicker">READ-ONLY PRODUCT RECORD</span><h2 id="product-details-title-{{ $detail['id'] }}">{{ $detail['name'] }}</h2></div><button type="button" popovertarget="product-details-{{ $detail['id'] }}" popovertargetaction="hide" aria-label="Close">×</button></header>
        <div class="details-grid">
            <h3 class="details-section">Product identification</h3>
            <div><small>Product ID</small><strong>#{{ $detail['id'] }}</strong></div><div><small>SKU</small><strong>{{ $detail['sku'] }}</strong></div>
            <div><small>Category</small><strong>{{ $detail['category'] }}</strong></div><div><small>Shelf Location</small><strong>{{ $detail['shelfLocation'] }}</strong></div>
            <div><small>Manufacturer</small><strong>{{ $detail['manufacturer'] }}</strong></div><div><small>Manufacturer Part Number</small><strong>{{ $detail['manufacturerPartNumber'] }}</strong></div>
            <div><small>Catalog Status</small><strong>{{ $detail['catalogStatus'] }}</strong></div><div><small>Stock Status</small><strong>{{ $detail['status'] }}</strong></div>
            <div class="wide detail-description"><small>Description</small><strong>{{ $detail['description'] }}</strong></div>

            <h3 class="details-section">Pricing and inventory</h3>
            <div><small>Unit Cost</small><strong>₱{{ $detail['unitCost'] }}</strong></div><div><small>Selling Price</small><strong>₱{{ $detail['unitPrice'] }}</strong></div>
            <div><small>Profit per Unit</small><strong>{{ $detail['grossProfit'] }}</strong></div>
            <div><small>Current Stock</small><strong>{{ $detail['stock'] }} units</strong></div><div><small>Reorder Level</small><strong>{{ $detail['reorder'] }} units</strong></div>
            <div><small>Current Inventory Value</small><strong>₱{{ $detail['inventoryValue'] }}</strong></div><div><small>Active Promotion</small><strong>{{ $detail['promotion'] }}</strong></div>

            <h3 class="details-section">Recorded activity</h3>
            <div><small>Units Sold</small><strong>{{ $detail['unitsSold'] }} units</strong></div><div><small>Recorded Sales Revenue</small><strong>₱{{ $detail['salesRevenue'] }}</strong></div>
            <div><small>Returned Units</small><strong>{{ $detail['returnedUnits'] }} units</strong></div><div><small>Damaged Units</small><strong>{{ $detail['damagedUnits'] }} units</strong></div>
            <div><small>Inventory Movement Records</small><strong>{{ $detail['movementCount'] }}</strong></div><div><small>Matched Suppliers</small><strong>{{ $detail['supplierCount'] }}</strong></div>
            <div class="wide"><small>Latest Inventory Movement</small><strong>{{ $detail['latestMovement'] }}</strong></div>
            <div class="wide detail-multiline"><small>Supplier Price Information</small><strong>{{ $detail['supplierPrices'] }}</strong></div>

            <h3 class="details-section">Record history</h3>
            <div><small>Created</small><strong>{{ $detail['created'] }}</strong></div><div><small>Last Updated</small><strong>{{ $detail['updated'] }}</strong></div>
        </div>
        <footer><span>This popup does not allow product changes.</span><button type="button" popovertarget="product-details-{{ $detail['id'] }}" popovertargetaction="hide">Close</button></footer>
    </section>
@endforeach
</body>
</html>
