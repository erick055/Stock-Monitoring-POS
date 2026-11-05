@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
] : [
    ['house-door','Dashboard','/staff/dashboard'], ['box-seam','Products','/staff/products'],
    ['cart3','POS Checkout','/staff/pos'], ['arrow-repeat','Return & Damage','/staff/returns'], ['gear','Part Compatibility','/staff/compatibility'],
];
$activeIndex = $isAdmin ? 2 : 1;
$productDetailRecords = [];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/products.css','resources/css/sorting-controls.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/products.js'])
    @include('partials.appearance')
</head>
<body data-live-inventory-page data-live-inventory-url="{{ route('inventory.live') }}" data-inventory-version="{{ $inventoryVersion }}">
<div class="dashboard-shell products-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === $activeIndex ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main products-main">
        <header class="products-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div><p class="welcome">READ-ONLY CATALOG</p><h1>Products</h1><p>View product information, pricing, and current inventory balances.</p></div>
            <span class="read-only-badge">View only</span>
        </header>

        @include('partials.inventory-update-notice')
        <section class="stat-grid product-stats" aria-label="Product summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL PRODUCTS</span><span class="trend-dot"></span></div><strong data-live-summary="total_sku">{{ number_format($summary['total_products']) }}</strong><small>Active catalog items</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>CATEGORIES</span><span class="trend-dot"></span></div><strong data-live-summary="categories">{{ number_format($summary['categories']) }}</strong><small>Active categories</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>AVERAGE PROFIT</span><span class="trend-dot"></span></div><strong data-live-summary="average_profit">{{ \App\Support\Display::money($summary['average_profit']) }}</strong><small>Profit per unit</small></article>
            <article class="stat-card purple"><div class="stat-head"><span>STOCK VALUE</span><span class="trend-dot"></span></div><strong data-live-summary="stock_value">{{ \App\Support\Display::money($summary['total_value']) }}</strong><small>At current unit cost</small></article>
        </section>

        <section class="panel products-panel" id="product-table">
            <div class="products-toolbar">
                <div><span class="section-kicker">PRODUCT CATALOG</span><h2>Products Inventory</h2></div>
                <form class="toolbar-controls" method="GET" data-products-filter>
                    <input type="hidden" name="per_page" value="{{ $products->perPage() }}">
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
                    <thead><tr><th>Product ID</th><th>Product</th><th>Category</th><th>Shelf location</th><th>Unit cost</th><th>Selling price</th><th>Stock</th><th>Profit</th><th>Status</th><th>Dead Stock Status</th><th>Details</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        @php
                            $grossProfit = (float) $product->unit_price - (float) $product->unit_cost;
                            $status = $product->stock_status;
                            $promotion = $product->activePromotion;
                            $promotionText = $promotion
                                ? $promotion->action_label.' · '.($promotion->action_type === 'promo_bundle'
                                    ? ($promotion->bundleProduct ? 'Free '.$promotion->bundleProduct->name : ($promotion->bundle_note ?: 'Bundle offer'))
                                    : number_format((float) $promotion->discount_percent, 1).'% off · '.\App\Support\Display::money($promotion->promotional_price))
                                : 'No active promotion';
                            $supplierPriceText = $product->supplierPrices->isEmpty()
                                ? 'No matched supplier prices'
                                : $product->supplierPrices->sortBy('unit_price')->map(function ($price) {
                                    $availability = $price->available_quantity === null ? 'stock unknown' : number_format($price->available_quantity).' available';
                                    $updated = $price->last_updated_at?->format('M d, Y') ?? 'date unavailable';
                                    return ($price->supplier?->name ?? 'Deleted supplier').' · '.($price->currency === 'PHP' ? \App\Support\Display::money($price->unit_price) : $price->currency.' '.number_format((float) $price->unit_price, 2)).' · '.$availability.' · updated '.$updated;
                                })->join("\n");
                            $latestLedger = $product->latestLedger;
                            $latestMovement = $latestLedger
                                ? (($latestLedger->qty_in > 0 ? '+' : '-').\App\Support\Display::count($latestLedger->qty_in > 0 ? $latestLedger->qty_in : $latestLedger->qty_out, 'unit').' · '.str_replace('_', ' ', $latestLedger->reason_code).' · '.$latestLedger->created_at->format('M d, Y h:i A').' · '.($latestLedger->user?->name ?? 'System'))
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
                                'deadStockStatus' => $deadStockStatuses[$product->product_id],
                                'unitCost' => number_format($product->unit_cost, 2),
                                'unitPrice' => number_format($product->unit_price, 2),
                                'grossProfit' => \App\Support\Display::money($grossProfit),
                                'stock' => number_format($product->current_stock),
                                'reorder' => number_format($product->reorder_level),
                                'inventoryValue' => number_format($product->current_stock * (float) $product->unit_cost, 2),
                                'status' => $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical'),
                                'unitsSold' => number_format((int) ($product->units_sold ?? 0)),
                                'grossSales' => number_format((float) $product->gross_sales, 2),
                                'refunds' => number_format((float) $product->refunds, 2),
                                'salesProfit' => number_format((float) $product->sales_profit, 2),
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
                        <tr data-live-product-id="{{ $product->product_id }}">
                            <td>#{{ $product->product_id }}</td>
                            <td><div class="product-cell"><span class="product-thumb">{{ strtoupper(substr($product->name, 0, 1)) }}</span><div><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></div></div></td>
                            <td>{{ $product->category ?: 'Uncategorized' }}</td>
                            <td><span class="shelf-location">{{ $product->shelf_location ?: 'Not assigned' }}</span></td>
                            <td data-live-cost>{{ \App\Support\Display::money($product->unit_cost) }}</td>
                            <td><strong data-live-price>{{ \App\Support\Display::money($product->unit_price) }}</strong></td>
                            <td data-live-stock>{{ \App\Support\Display::count($product->current_stock, 'unit') }}</td>
                            <td><span data-live-profit class="profit-badge">{{ \App\Support\Display::money($grossProfit) }}</span></td>
                            <td><span data-live-status class="product-status {{ $status === 'healthy' ? 'active' : 'low' }}">{{ $status === 'healthy' ? 'In stock' : ($status === 'warning' ? 'Low stock' : 'Critical') }}</span></td>
                            <td><span class="product-status {{ $detailData['deadStockStatus'] === 'Healthy' ? 'active' : ($detailData['deadStockStatus'] === 'Dead Stock' ? 'low' : '') }}">{{ $detailData['deadStockStatus'] }}</span></td>
                            <td><button class="view-product" type="button" popovertarget="product-details-{{ $product->product_id }}">View</button></td>
                        </tr>
                    @empty
                        <tr><td colspan="11"><div class="empty-products"><span>⌕</span><strong>No products found</strong><small>Try another name, SKU, category, shelf, or filter.</small></div></td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @include('partials.ml-table-pagination', ['rows' => $products, 'sizeName' => 'per_page', 'pageName' => 'page', 'anchor' => 'product-table', 'tableLabel' => 'Products'])
        </section>
    </main>
</div>

<div id="product-table-associated">
@foreach($productDetailRecords as $detail)
    <section class="product-details" data-live-product-id="{{ $detail['id'] }}" id="product-details-{{ $detail['id'] }}" popover aria-labelledby="product-details-title-{{ $detail['id'] }}">
        <header><div><span class="section-kicker">READ-ONLY PRODUCT RECORD</span><h2 id="product-details-title-{{ $detail['id'] }}">{{ $detail['name'] }}</h2></div><button type="button" popovertarget="product-details-{{ $detail['id'] }}" popovertargetaction="hide" aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
        <div class="details-grid">
            <h3 class="details-section">Product identification</h3>
            <div><small>Product ID</small><strong>#{{ $detail['id'] }}</strong></div><div><small>SKU</small><strong>{{ $detail['sku'] }}</strong></div>
            <div><small>Category</small><strong>{{ $detail['category'] }}</strong></div><div><small>Shelf Location</small><strong>{{ $detail['shelfLocation'] }}</strong></div>
            <div><small>Manufacturer</small><strong>{{ $detail['manufacturer'] }}</strong></div><div><small>Manufacturer Part Number</small><strong>{{ $detail['manufacturerPartNumber'] }}</strong></div>
            <div><small>Catalog Status</small><strong>{{ $detail['catalogStatus'] }}</strong></div><div><small>Stock Status</small><strong>{{ $detail['status'] }}</strong></div>
            <div><small>Dead Stock Status</small><strong>{{ $detail['deadStockStatus'] }}</strong></div>
            <div class="wide detail-description"><small>Description</small><strong>{{ $detail['description'] }}</strong></div>

            <h3 class="details-section">Pricing and inventory</h3>
            <div><small>Unit Cost</small><strong data-live-cost>{{ \App\Support\Display::money(str_replace(',', '', $detail['unitCost'])) }}</strong></div><div><small>Selling Price</small><strong data-live-price>{{ \App\Support\Display::money(str_replace(',', '', $detail['unitPrice'])) }}</strong></div>
            <div><small>Profit per Unit</small><strong data-live-profit>{{ $detail['grossProfit'] }}</strong></div>
            <div><small>Current Stock</small><strong data-live-stock>{{ \App\Support\Display::count($detail['stock'], 'unit') }}</strong></div><div><small>Reorder Level</small><strong data-live-reorder>{{ \App\Support\Display::count($detail['reorder'], 'unit') }}</strong></div>
            <div><small>Current Inventory Value</small><strong data-live-value>{{ \App\Support\Display::money(str_replace(',', '', $detail['inventoryValue'])) }}</strong></div><div><small>Active Promotion</small><strong>{{ $detail['promotion'] }}</strong></div>

            <h3 class="details-section">Recorded activity</h3>
            <div><small>Units Sold</small><strong>{{ \App\Support\Display::count($detail['unitsSold'], 'unit') }}</strong></div><div><small>Net Sales Revenue</small><strong>{{ \App\Support\Display::money(str_replace(',', '', $detail['salesRevenue'])) }}</strong></div>
            <div><small>Gross Sales</small><strong>{{ \App\Support\Display::money(str_replace(',', '', $detail['grossSales'])) }}</strong></div><div><small>Refunds</small><strong>{{ \App\Support\Display::money(str_replace(',', '', $detail['refunds'])) }}</strong></div><div><small>Recorded Gross Profit</small><strong>{{ \App\Support\Display::money(str_replace(',', '', $detail['salesProfit'])) }}</strong></div>
            <div><small>Returned Units</small><strong>{{ \App\Support\Display::count($detail['returnedUnits'], 'unit') }}</strong></div><div><small>Damaged Units</small><strong>{{ \App\Support\Display::count($detail['damagedUnits'], 'unit') }}</strong></div>
            <div><small>Inventory Movement Records</small><strong>{{ $detail['movementCount'] }}</strong></div><div><small>Matched Suppliers</small><strong>{{ $detail['supplierCount'] }}</strong></div>
            <div class="wide"><small>Latest Inventory Movement</small><strong>{{ $detail['latestMovement'] }}</strong></div>
            <div class="wide detail-multiline"><small>Supplier Price Information</small><strong>{{ $detail['supplierPrices'] }}</strong></div>

            <h3 class="details-section">Record history</h3>
            <div><small>Created</small><strong>{{ $detail['created'] }}</strong></div><div><small>Last Updated</small><strong>{{ $detail['updated'] }}</strong></div>
        </div>
        <footer><span>This popup does not allow product changes.</span><button type="button" popovertarget="product-details-{{ $detail['id'] }}" popovertargetaction="hide">Close</button></footer>
    </section>
@endforeach
</div>
@include('partials.login-stock-alert')
</body>
</html>
