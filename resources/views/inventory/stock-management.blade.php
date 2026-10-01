@php
$navigation = [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','#'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
];
$productStoreRoute = route('admin.inventory.products.store');
$movementStoreRoute = route('admin.inventory.movements.store');
$editErrorProduct = $errors->getBag('editProduct')->any()
    ? $allProducts->firstWhere('product_id', (int) old('edit_product_id'))
    : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stock Management | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/stock-management.css','resources/css/sorting-controls.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/stock-management.js'])
</head>
<body data-live-inventory-page data-live-inventory-url="{{ route('inventory.live') }}" data-inventory-version="{{ $inventoryVersion }}">
<div class="dashboard-shell stock-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 1 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main stock-main">
        <header class="stock-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div><p class="welcome">INVENTORY CONTROL</p><h1>Stock Management</h1><p>Monitor inventory levels and record every stock movement.</p></div>
            <form class="header-tools" method="GET">
                <label class="search-box"><span>⌕</span><input type="search" name="search" value="{{ $search }}" placeholder="Search SKU, product, or shelf"></label>
                <input type="hidden" name="status" value="{{ $status }}">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <button class="search-button" type="submit">Search</button>
            </form>
        </header>

        @if(session('success'))<div class="flash-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())
            <div class="flash-message error" role="alert"><strong>Please fix the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        @if($errors->getBag('editProduct')->any())
            <div class="flash-message error" role="alert"><strong>The product was not updated:</strong><ul>@foreach($errors->getBag('editProduct')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
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
                    <form method="GET" data-filter-form>
                        <input type="hidden" name="search" value="{{ $search }}">
                        <select name="status" data-status-filter aria-label="Filter stock status"><option value="all" @selected($status === 'all')>All statuses</option><option value="healthy" @selected($status === 'healthy')>Healthy</option><option value="warning" @selected($status === 'warning')>Low stock</option><option value="critical" @selected($status === 'critical')>Critical</option></select>
                        <select name="sort" aria-label="Sort stock"><option value="name" @selected($sort === 'name')>Name A–Z</option><option value="name_desc" @selected($sort === 'name_desc')>Name Z–A</option><option value="newest" @selected($sort === 'newest')>Newest added</option><option value="stock_high" @selected($sort === 'stock_high')>Stock: high to low</option><option value="stock_low" @selected($sort === 'stock_low')>Stock: low to high</option><option value="cost_high" @selected($sort === 'cost_high')>Cost: high to low</option><option value="cost_low" @selected($sort === 'cost_low')>Cost: low to high</option></select>
                        <button class="sort-stock-button" type="submit">Sort</button>
                    </form>
                    <button class="add-product" type="button" data-open-product>+ Add Product</button>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Product ID</th><th>Product</th><th>Category</th><th>Shelf location</th><th>Current stock</th><th>Reorder level</th><th>Unit cost</th><th>Selling price</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody>
                    @forelse($products as $product)
                        <tr>
                            <td>#{{ $product->product_id }}</td>
                            <td><strong>{{ $product->name }}</strong><small>{{ $product->sku }}</small></td>
                            <td>{{ $product->category ?: 'Uncategorized' }}</td>
                            <td>
                                <form class="shelf-location-form" method="POST" action="{{ route('admin.inventory.products.shelf-location', $product) }}">
                                    @csrf @method('PATCH')
                                    <input name="shelf_location" value="{{ $product->shelf_location }}" maxlength="100" aria-label="Shelf location for {{ $product->name }}" placeholder="Not assigned">
                                    <button type="submit">Save</button>
                                </form>
                            </td>
                            <td><div class="stock-level"><span>{{ number_format($product->current_stock) }} units</span><div><i style="width:{{ min(100, $product->reorder_level ? ($product->current_stock / ($product->reorder_level * 3)) * 100 : 100) }}%"></i></div></div></td>
                            <td>{{ number_format($product->reorder_level) }}</td>
                            <td>₱{{ number_format($product->unit_cost, 2) }}</td>
                            <td>₱{{ number_format($product->unit_price, 2) }}</td>
                            <td><span class="status-badge {{ $product->stock_status }}">{{ $product->stock_status === 'warning' ? 'Low stock' : ucfirst($product->stock_status) }}</span></td>
                            <td><div class="product-row-actions">
                                <button
                                    class="edit-product-button"
                                    type="button"
                                    data-edit-product
                                    data-edit-action="{{ route('admin.inventory.products.update', $product) }}"
                                    data-product-id="{{ $product->product_id }}"
                                    data-product-sku="{{ $product->sku }}"
                                    data-product-name="{{ $product->name }}"
                                    data-product-category="{{ $product->category }}"
                                    data-product-shelf-location="{{ $product->shelf_location }}"
                                    data-product-manufacturer="{{ $product->manufacturer }}"
                                    data-product-manufacturer-part-number="{{ $product->manufacturer_part_number }}"
                                    data-product-description="{{ $product->description }}"
                                    data-product-unit-cost="{{ $product->unit_cost }}"
                                    data-product-unit-price="{{ $product->unit_price }}"
                                    data-product-reorder-level="{{ $product->reorder_level }}"
                                    data-product-stock="{{ $product->current_stock }}"
                                    data-product-promotion="{{ $product->activePromotion?->action_label }}"
                                >Edit</button>
                                <button
                                    class="delete-product-button"
                                    type="button"
                                    data-delete-product
                                    data-delete-action="{{ route('admin.inventory.products.destroy', $product) }}"
                                    data-product-name="{{ $product->name }}"
                                    data-product-sku="{{ $product->sku }}"
                                    data-product-stock="{{ $product->current_stock }}"
                                >Delete</button>
                            </div></td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="empty-cell">No products found. Add your first product to begin.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel adjustment-panel">
            <div class="section-heading movement-heading">
                <div><span class="section-kicker">INVENTORY ENTRY</span><h2>Record Stock Movement</h2><small>Use one form for received stock, released stock, or inventory corrections.</small></div>
            </div>
            <form class="movement-form" method="POST" action="{{ $movementStoreRoute }}" data-movement-form>
                @csrf
                <div class="movement-guidance" data-movement-guidance role="status">
                    <strong data-guidance-title>Stock In adds units to the selected product.</strong>
                    <span data-guidance-text>Use this for supplier deliveries, customer returns accepted back into stock, or recovered inventory.</span>
                </div>

                <div class="movement-fields">
                    <label>1. Movement type
                        <select name="movement_type" required data-movement-type>
                            <option value="in" @selected(old('movement_type', 'in') === 'in')>Stock In — add inventory</option>
                            <option value="out" @selected(old('movement_type') === 'out')>Stock Out — remove inventory</option>
                            <option value="adjustment" @selected(old('movement_type') === 'adjustment')>Adjustment — correct inventory count</option>
                        </select>
                        <small>Choose what should happen to the selected product's balance.</small>
                    </label>
                    <label>2. Product
                        <select name="product_id" required data-movement-product>
                            <option value="">Select product</option>
                            @foreach($allProducts as $product)
                                <option value="{{ $product->product_id }}" data-stock="{{ $product->current_stock }}" @selected((string) old('product_id') === (string) $product->product_id)>{{ $product->sku }} — {{ $product->name }} ({{ $product->current_stock }} currently)</option>
                            @endforeach
                        </select>
                    </label>
                    <label><span data-quantity-label>3. Quantity received</span>
                        <input name="quantity" value="{{ old('quantity') }}" type="number" min="1" placeholder="Enter a positive quantity" required data-movement-quantity>
                        <small data-quantity-help>Entered units will be added to current stock.</small>
                    </label>
                    <label>4. Reason
                        <select name="reason_code" required data-movement-reason data-old-reason="{{ old('reason_code') }}">
                            <option value="">Select reason</option>
                            <option value="PURCHASE_RECEIPT" data-types="in">Supplier delivery / purchase receipt</option>
                            <option value="RETURN_TO_STOCK" data-types="in">Customer return accepted to stock</option>
                            <option value="RECOVERED_STOCK" data-types="in">Recovered or previously unrecorded stock</option>
                            <option value="SALE" data-types="out">Manual sale or release</option>
                            <option value="DAMAGED" data-types="out">Damaged or unusable goods</option>
                            <option value="SUPPLIER_RETURN" data-types="out">Returned to supplier</option>
                            <option value="INTERNAL_USE" data-types="out">Internal or shop use</option>
                            <option value="PHYSICAL_COUNT" data-types="adjustment">Physical count correction</option>
                            <option value="DATA_CORRECTION" data-types="adjustment">Encoding or data correction</option>
                            <option value="SHRINKAGE" data-types="adjustment">Loss, shrinkage, or discrepancy</option>
                        </select>
                    </label>
                    <label>5. Reference number <small>Optional</small>
                        <input name="reference" value="{{ old('reference') }}" maxlength="100" placeholder="Invoice, delivery receipt, sale, or memo no.">
                    </label>
                    <label><span data-counterparty-label>6. Supplier / source</span> <small>Optional</small>
                        <input name="counterparty" value="{{ old('counterparty') }}" maxlength="150" placeholder="Who supplied or received the items" data-counterparty-input>
                    </label>
                    <label class="field-wide">7. Movement details <small>Optional</small>
                        <textarea name="logs" rows="3" maxlength="1000" placeholder="Add condition, purpose, authorization, count findings, or other useful documentation">{{ old('logs') }}</textarea>
                    </label>
                </div>

                <div class="movement-audit-note">
                    <span>Recorded by <strong>{{ auth()->user()->name }}</strong></span>
                    <span>Date and time are saved automatically when submitted.</span>
                </div>

                <div class="movement-result" aria-live="polite">
                    <span><small>Current stock</small><strong data-current-stock>—</strong></span>
                    <span><small>Movement</small><strong data-stock-change>—</strong></span>
                    <span><small>Resulting stock</small><strong data-resulting-stock>—</strong></span>
                </div>
                <button class="movement-submit" type="submit" @disabled($allProducts->isEmpty()) data-movement-submit>Record stock in</button>
            </form>
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

<div class="stock-modal" data-product-modal data-open-on-error="{{ $errors->getBag('addProduct')->any() ? 'true' : 'false' }}" hidden>
    <div class="modal-backdrop" data-close-product></div>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="add-product-title">
        <div class="modal-header"><div><span class="section-kicker">NEW INVENTORY ITEM</span><h2 id="add-product-title">Add Product</h2></div><button type="button" data-close-product aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button></div>
        @if($errors->getBag('addProduct')->any())
            <div class="modal-form-errors" role="alert"><strong>The product was not added:</strong><ul>@foreach($errors->getBag('addProduct')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ $productStoreRoute }}" class="product-form">
            @csrf
            <div class="form-grid">
                <label>SKU<input name="sku" value="{{ old('sku') }}" maxlength="100" required></label>
                <label>Product name<input name="name" value="{{ old('name') }}" maxlength="255" required></label>
                <label>Category
                    <select name="category" data-category-select>
                        <option value="">Select a category</option>
                        @foreach($categories as $category)
                            <option value="{{ $category }}" @selected(old('category') === $category)>{{ $category }}</option>
                        @endforeach
                        <option value="__new__" @selected(old('category') === '__new__')>+ Add new category</option>
                    </select>
                </label>
                <label data-new-category-field @if(old('category') !== '__new__') hidden @endif>New category
                    <input name="new_category" value="{{ old('new_category') }}" maxlength="100" placeholder="e.g. Lubricants" @if(old('category') === '__new__') required @else disabled @endif>
                </label>
                <label>Shelf location
                    <select name="shelf_location" data-option-select data-new-value="__new__" data-new-field="new-shelf-field">
                        <option value="">Select a shelf</option>
                        @foreach($shelves as $shelf)
                            <option value="{{ $shelf }}" @selected(old('shelf_location') === $shelf)>{{ $shelf }}</option>
                        @endforeach
                        <option value="__new__" @selected(old('shelf_location') === '__new__')>+ Add new shelf</option>
                    </select>
                </label>
                <label data-new-option-field="new-shelf-field" @if(old('shelf_location') !== '__new__') hidden @endif>New shelf
                    <input name="new_shelf_location" value="{{ old('new_shelf_location') }}" maxlength="100" placeholder="e.g. Aisle A · Shelf 03 · Bin 2" @if(old('shelf_location') === '__new__') required @else disabled @endif>
                </label>
                <label>Manufacturer<input name="manufacturer" value="{{ old('manufacturer') }}" maxlength="150" placeholder="e.g. Honda, NGK, DID"></label>
                <label>Manufacturer part number (required)<input name="manufacturer_part_number" value="{{ old('manufacturer_part_number') }}" maxlength="150" required placeholder="Official number from manufacturer or packaging"></label>
                <label>Opening Qty In<input name="qty_in" value="{{ old('qty_in', 0) }}" type="number" min="0" required></label>
                <label>Unit cost (₱)<input name="unit_cost" value="{{ old('unit_cost', 0) }}" type="number" min="0" step="0.01" required></label>
                <label>Selling price (₱)<input name="unit_price" value="{{ old('unit_price', 0) }}" type="number" min="0" step="0.01" required></label>
                <label>Reorder level<input name="reorder_level" value="{{ old('reorder_level', 5) }}" type="number" min="0" required></label>
                <label>Reason code<select name="reason_code" required><option value="OPENING_STOCK">Opening stock</option><option value="PURCHASE_RECEIPT">Purchase receipt</option><option value="NEW_PRODUCT">New product</option></select></label>
            </div>
            <label>Manufacturer description<textarea name="description" rows="2" maxlength="5000" placeholder="Official product description">{{ old('description') }}</textarea></label>
            <label>Inventory log<textarea name="logs" rows="3" maxlength="1000" placeholder="Describe when or why this product was added">{{ old('logs') }}</textarea></label>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-product>Cancel</button><button type="submit" class="primary-button">Add product and ledger entry</button></div>
        </form>
    </section>
</div>

<div class="stock-modal" data-edit-product-modal data-open-on-error="{{ $editErrorProduct ? 'true' : 'false' }}" hidden>
    <div class="modal-backdrop" data-close-edit-product></div>
    <section class="modal-card" role="dialog" aria-modal="true" aria-labelledby="edit-product-title">
        <div class="modal-header">
            <div><span class="section-kicker">PRODUCT INFORMATION</span><h2 id="edit-product-title">Edit <span data-edit-product-title>{{ $editErrorProduct?->name }}</span></h2></div>
            <button type="button" data-close-edit-product aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        @if($errors->getBag('editProduct')->any())
            <div class="modal-form-errors" role="alert"><strong>Please correct:</strong><ul>@foreach($errors->getBag('editProduct')->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <form method="POST" action="{{ $editErrorProduct ? route('admin.inventory.products.update', $editErrorProduct) : '' }}" class="product-form" data-edit-product-form>
            @csrf @method('PATCH')
            <input type="hidden" name="edit_product_id" value="{{ old('edit_product_id', $editErrorProduct?->product_id) }}">
            <div class="form-grid">
                <label>SKU<input name="sku" value="{{ old('sku', $editErrorProduct?->sku) }}" maxlength="100" required></label>
                <label>Product name<input name="name" value="{{ old('name', $editErrorProduct?->name) }}" maxlength="255" required></label>
                @php($editCategory = old('category', $editErrorProduct?->category))
                @php($editShelf = old('shelf_location', $editErrorProduct?->shelf_location))
                <label>Category
                    <select name="category" data-option-select data-new-value="__new__" data-new-field="edit-new-category-field">
                        <option value="">Select a category</option>
                        @foreach($categories as $category)<option value="{{ $category }}" @selected($editCategory === $category)>{{ $category }}</option>@endforeach
                        <option value="__new__" @selected($editCategory === '__new__')>+ Add new category</option>
                    </select>
                </label>
                <label data-new-option-field="edit-new-category-field" @if($editCategory !== '__new__') hidden @endif>New category
                    <input name="new_category" value="{{ old('new_category') }}" maxlength="100" placeholder="e.g. Lubricants" @if($editCategory === '__new__') required @else disabled @endif>
                </label>
                <label>Shelf location
                    <select name="shelf_location" data-option-select data-new-value="__new__" data-new-field="edit-new-shelf-field">
                        <option value="">Select a shelf</option>
                        @foreach($shelves as $shelf)<option value="{{ $shelf }}" @selected($editShelf === $shelf)>{{ $shelf }}</option>@endforeach
                        <option value="__new__" @selected($editShelf === '__new__')>+ Add new shelf</option>
                    </select>
                </label>
                <label data-new-option-field="edit-new-shelf-field" @if($editShelf !== '__new__') hidden @endif>New shelf
                    <input name="new_shelf_location" value="{{ old('new_shelf_location') }}" maxlength="100" placeholder="e.g. Aisle A · Shelf 03 · Bin 2" @if($editShelf === '__new__') required @else disabled @endif>
                </label>
                <label>Manufacturer<input name="manufacturer" value="{{ old('manufacturer', $editErrorProduct?->manufacturer) }}" maxlength="150" placeholder="e.g. Honda, NGK, DID"></label>
                <label>Manufacturer part number (required)<input name="manufacturer_part_number" value="{{ old('manufacturer_part_number', $editErrorProduct?->manufacturer_part_number) }}" maxlength="150" required placeholder="Official number from manufacturer or packaging"></label>
                <label>Unit cost (₱)<input name="unit_cost" value="{{ old('unit_cost', $editErrorProduct?->unit_cost) }}" type="number" min="0" max="9999999999.99" step="0.01" required></label>
                <label>Selling price (₱)<input name="unit_price" value="{{ old('unit_price', $editErrorProduct?->unit_price) }}" type="number" min="0" max="9999999999.99" step="0.01" required></label>
                <label>Reorder level<input name="reorder_level" value="{{ old('reorder_level', $editErrorProduct?->reorder_level) }}" type="number" min="0" required></label>
                <div class="stock-readonly"><small>Current stock</small><strong data-edit-product-stock>{{ $editErrorProduct?->current_stock ?? 0 }} units</strong><span>Use Record Stock Movement to change this balance.</span></div>
            </div>
            <label>Product description<textarea name="description" rows="3" maxlength="5000" placeholder="Product specifications or useful details">{{ old('description', $editErrorProduct?->description) }}</textarea></label>
            <p class="promotion-edit-note" data-edit-promotion-note hidden></p>
            <div class="modal-actions"><button type="button" class="secondary-button" data-close-edit-product>Cancel</button><button type="submit" class="primary-button">Save product changes</button></div>
        </form>
    </section>
</div>

<div class="stock-modal" data-delete-product-modal hidden>
    <div class="modal-backdrop" data-close-delete-product></div>
    <section class="modal-card delete-product-card" role="dialog" aria-modal="true" aria-labelledby="delete-product-title">
        <div class="modal-header">
            <div><span class="section-kicker danger-kicker">REMOVE PRODUCT</span><h2 id="delete-product-title">Delete Product</h2></div>
            <button type="button" data-close-delete-product aria-label="Close">&times;</button>
        </div>
        <div class="delete-product-summary">
            <strong data-delete-product-name>Select a product</strong>
            <span data-delete-product-stock></span>
            <p>This removes the product from the active catalog. Existing sales and inventory history will be kept for reports and documentation.</p>
        </div>
        <p class="delete-product-warning" data-delete-product-warning hidden>This product still has stock. Record a stock-out or adjustment to zero before deleting it.</p>
        <form method="POST" action="" class="product-form delete-product-form" data-delete-product-form>
            @csrf
            @method('DELETE')
            <label>Reason for deletion
                <textarea name="deletion_reason" rows="3" maxlength="255" placeholder="Example: Discontinued product or duplicate record" required></textarea>
            </label>
            <label>Confirm with your account password
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            <div class="modal-actions">
                <button type="button" class="secondary-button" data-close-delete-product>Cancel</button>
                <button type="submit" class="danger-button" data-delete-product-submit>Delete product</button>
            </div>
        </form>
    </section>
</div>
</body>
</html>
