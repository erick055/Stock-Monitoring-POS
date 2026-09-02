@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Price | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/suppliers.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/suppliers.js'])
</head>
<body>
<div class="dashboard-shell suppliers-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 7 ? 'active' : '' }}" href="{{ url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main suppliers-main">
        <header class="suppliers-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">REAL SUPPLIER PRICE IMPORTS</p>
                <h1>Supplier Price</h1>
                <p>Upload, validate, review, and publish supplier CSV or Excel price lists.</p>
            </div>
        </header>

        @if(session('success'))<div class="supplier-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())
            <div class="supplier-message error" role="alert"><strong>Please correct the following:</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <section class="stat-grid suppliers-stats" aria-label="Supplier price summary">
            <article class="stat-card purple"><div class="stat-head"><span>ACTIVE SUPPLIERS</span></div><strong>{{ $summary['suppliers'] }}</strong><small>With imported pricing</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>PUBLISHED PRICES</span></div><strong>{{ $summary['prices'] }}</strong><small>Current supplier SKUs</small></article>
            <article class="stat-card orange"><div class="stat-head"><span>PRICE CHANGES</span></div><strong>{{ $summary['changes'] }}</strong><small>Changed since previous import</small></article>
            <article class="stat-card red"><div class="stat-head"><span>STALE PRICES</span></div><strong>{{ $summary['stale'] }}</strong><small>Not updated for 30 days</small></article>
        </section>

        @if($summary['suppliers'] || $summary['prices'] || $imports->isNotEmpty())
            <details class="panel supplier-danger-zone">
                <summary>Delete all supplier price data</summary>
                <div class="danger-zone-content">
                    <div>
                        <strong>Start supplier pricing from a clean slate</strong>
                        <p>This permanently removes suppliers, published prices, price history, staged rows, and import history. Products, inventory quantities, product costs, sales, and POS records are not changed.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.suppliers.purge') }}" data-supplier-purge>
                        @csrf @method('DELETE')
                        <label>Confirm with your account password
                            <input name="password" type="password" autocomplete="current-password" placeholder="Enter your password" data-purge-password required>
                        </label>
                        <button class="delete-all-button" type="submit" data-purge-button>Delete all supplier data</button>
                    </form>
                </div>
            </details>
        @endif

        <section class="panel import-panel">
            <div class="section-heading">
                <div><span class="section-kicker">STEP 1</span><h2>Upload supplier price list</h2></div>
                <span class="source-badge">CSV or XLSX · maximum 10 MB / 2,000 rows</span>
            </div>
            <form class="supplier-import-form" method="POST" action="{{ route('admin.suppliers.imports.upload') }}" enctype="multipart/form-data">
                @csrf
                <label>Supplier name<input name="supplier_name" value="{{ old('supplier_name') }}" required maxlength="150" placeholder="ABC Motor Parts"></label>
                <label>Supplier code<input name="supplier_code" value="{{ old('supplier_code') }}" required maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="ABC-MOTOR"></label>
                <label class="file-field">Price-list file<input name="price_file" type="file" accept=".csv,.xlsx" required data-supplier-file><span data-file-name>Choose CSV or XLSX file</span></label>
                <button class="apply-button" type="submit">Upload and preview</button>
            </form>
            <div class="format-guide">
                <strong>Required columns:</strong>
                <code>supplier_sku</code><code>product_name</code><code>unit_price</code>
                <strong>Optional:</strong>
                <code>internal_sku</code><code>currency</code><code>available_quantity</code><code>minimum_order_quantity</code><code>lead_time_days</code><code>effective_date</code>
            </div>
            <p class="import-note">Matching checks internal SKU first, then supplier SKU, then a unique exact product name. Publishing does not change product costs or store inventory until the owner applies an item.</p>
        </section>

        @if($selectedImport)
            <section class="panel preview-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">STEP 2 · REVIEW</span><h2>{{ $selectedImport->supplier->name }} — {{ $selectedImport->source_filename }}</h2></div>
                    <span class="import-status {{ $selectedImport->error_count ? 'has-errors' : 'ready' }}">{{ $selectedImport->valid_count }} valid · {{ $selectedImport->error_count }} errors</span>
                </div>
                <div class="supplier-table-wrap">
                    <table>
                        <thead><tr><th>Row</th><th>Supplier SKU</th><th>Product</th><th>MotoSync match</th><th>Price</th><th>Availability</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach($selectedImport->rows as $row)
                            <tr class="{{ $row->validation_errors ? 'row-error' : '' }}">
                                <td>{{ $row->row_number }}</td>
                                <td>{{ $row->supplier_sku }}</td>
                                <td>{{ $row->product_name }}</td>
                                <td>
                                    @if($row->product)
                                        <span class="match-label matched">Matched: {{ $row->product->sku }} — {{ $row->product->name }}</span>
                                    @else
                                        <span class="match-label unmatched">Unmatched{{ $row->internal_sku ? ': '.$row->internal_sku.' not found' : '' }}</span>
                                    @endif
                                </td>
                                <td>{{ $row->currency }} {{ number_format((float) $row->unit_price, 2) }}</td>
                                <td>{{ $row->available_quantity ?? 'Not supplied' }}</td>
                                <td>
                                    @if($row->validation_errors)
                                        <ul class="row-errors">@foreach($row->validation_errors as $error)<li>{{ $error }}</li>@endforeach</ul>
                                    @else
                                        <span class="valid-label">Ready</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="approval-actions">
                    <form method="POST" action="{{ route('admin.suppliers.imports.reject', $selectedImport) }}">@csrf<button class="reject-button" type="submit">Reject import</button></form>
                    <form method="POST" action="{{ route('admin.suppliers.imports.approve', $selectedImport) }}">@csrf<button class="apply-button" type="submit" @disabled($selectedImport->error_count > 0)>Approve and publish prices</button></form>
                </div>
            </section>
        @endif

        <section class="panel suppliers-panel">
            <div class="section-heading">
                <div><span class="section-kicker">CURRENT DATA</span><h2>Published supplier prices</h2></div>
            </div>
            <div class="pricing-list">
                @forelse($prices as $price)
                    @php
                        $change = $price->previous_price && (float) $price->previous_price > 0
                            ? (((float) $price->unit_price - (float) $price->previous_price) / (float) $price->previous_price) * 100
                            : null;
                        $isStale = $price->last_updated_at->lt(now()->subDays(30));
                    @endphp
                    <article class="pricing-card">
                        <div class="supplier-line">
                            <strong>{{ $price->product_name }}</strong>
                            <small>{{ $price->supplier->name }} · Supplier SKU: {{ $price->supplier_sku }}</small>
                            <em>{{ $price->product ? 'Matched: '.$price->product->sku : 'Unmatched supplier item' }}</em>
                        </div>
                        <div class="pricing-pill">{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}<span>Current price</span></div>
                        <div class="pricing-pill">{{ $price->previous_price ? $price->currency.' '.number_format((float) $price->previous_price, 2) : 'First import' }}<span>Previous price</span></div>
                        <div class="pricing-pill accent">{{ $change === null ? 'New' : sprintf('%+.1f%%', $change) }}<span>Change</span></div>
                        <div class="pricing-pill">{{ $price->available_quantity ?? 'Unknown' }}<span>Supplier stock</span></div>
                        <div class="pricing-pill {{ $isStale ? 'stale' : '' }}">{{ $isStale ? 'Stale' : $price->last_updated_at->diffForHumans() }}<span>Freshness</span></div>
                        <details class="catalog-sync">
                            <summary>{{ $price->product ? 'Manage product match and cost' : 'Match or add this item to Products' }}</summary>
                            <div class="catalog-sync-body">
                                <div class="sync-explanation">
                                    <strong>{{ $price->product ? 'Currently matched to '.$price->product->sku.' — '.$price->product->name : 'This supplier item is not connected to Products yet.' }}</strong>
                                    <span>Supplier stock is informational and will never be added to store inventory automatically.</span>
                                </div>

                                <form class="match-product-form" method="POST" action="{{ route('admin.suppliers.prices.match', $price) }}" data-catalog-match-form>
                                    @csrf @method('PATCH')
                                    <label>Match an existing product
                                        <input type="search" list="catalog-product-options" placeholder="Search SKU or product name" autocomplete="off" data-catalog-match-search required>
                                        <input type="hidden" name="product_id" data-catalog-product-id>
                                    </label>
                                    <button class="match-button" type="submit">Save match</button>
                                </form>

                                @if($price->product)
                                    <form class="apply-cost-form" method="POST" action="{{ route('admin.suppliers.prices.apply-cost', $price) }}" data-apply-supplier-cost data-product-name="{{ $price->product->name }}" data-cost="{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}">
                                        @csrf @method('PATCH')
                                        <div><small>Current product cost</small><strong>₱{{ number_format((float) $price->product->unit_cost, 2) }}</strong></div>
                                        <div><small>Supplier cost to apply</small><strong>{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}</strong></div>
                                        <button class="apply-button" type="submit" @disabled(strtoupper($price->currency) !== 'PHP')>Apply supplier cost</button>
                                    </form>
                                @else
                                    <form class="create-catalog-product" method="POST" action="{{ route('admin.suppliers.prices.create-product', $price) }}">
                                        @csrf
                                        <label>Product SKU<input name="sku" value="{{ $price->supplier_sku }}" maxlength="100" required></label>
                                        <label>Selling price (₱)<input name="selling_price" type="number" min="0" step="0.01" placeholder="Set owner selling price" required></label>
                                        <label>Category<input name="category" maxlength="100" placeholder="e.g. Brake Parts"></label>
                                        <label>Shelf location<input name="shelf_location" maxlength="100" placeholder="e.g. Aisle B · Shelf 2"></label>
                                        <label>Reorder level<input name="reorder_level" type="number" min="0" value="5" required></label>
                                        <button class="apply-button" type="submit" @disabled(strtoupper($price->currency) !== 'PHP')>Create in Products</button>
                                    </form>
                                @endif

                                @if(strtoupper($price->currency) !== 'PHP')
                                    <p class="currency-warning">Convert this supplier price to PHP before applying it to the product catalog.</p>
                                @endif
                            </div>
                        </details>
                    </article>
                @empty
                    <div class="empty-state">No supplier prices have been published. Upload a price list to begin.</div>
                @endforelse
            </div>
        </section>

        <datalist id="catalog-product-options">
            @foreach($catalogProducts as $catalogProduct)
                <option value="{{ $catalogProduct->sku }} — {{ $catalogProduct->name }} (#{{ $catalogProduct->product_id }})" data-product-id="{{ $catalogProduct->product_id }}"></option>
            @endforeach
        </datalist>

        <section class="panel imports-panel">
            <div class="section-heading"><div><span class="section-kicker">AUDIT TRAIL</span><h2>Recent imports</h2></div></div>
            <div class="import-list">
                @forelse($imports as $import)
                    <a href="{{ route('admin.suppliers', ['import' => $import->supplier_import_id]) }}">
                        <strong>{{ $import->supplier->name }}</strong>
                        <span>{{ $import->source_filename }}</span>
                        <span>{{ $import->row_count }} rows</span>
                        <span class="import-status {{ $import->status }}">{{ ucfirst($import->status) }}</span>
                        <time>{{ $import->created_at->format('M d, Y H:i') }}</time>
                    </a>
                @empty
                    <div class="empty-state">No import history yet.</div>
                @endforelse
            </div>
        </section>
    </main>
</div>
</body>
</html>
