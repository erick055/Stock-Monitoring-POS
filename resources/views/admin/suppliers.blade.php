@php
$navigation = [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['@','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','/admin/compatibility'], ['♟','Account Management','/admin/accounts'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Price | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/suppliers.css','resources/css/sorting-controls.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/suppliers.js'])
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

        @if($summary['prices'])
            <details class="panel supplier-danger-zone">
                <summary>Clear published supplier prices</summary>
                <div class="danger-zone-content">
                    <div>
                        <strong>Start active supplier pricing from a clean slate</strong>
                        <p>This clears published prices and deactivates their suppliers. Original import rows remain in the dated archive so you can review deleted prices later. Products, inventory quantities, product costs, sales, and POS records are unchanged.</p>
                    </div>
                    <form method="POST" action="{{ route('admin.suppliers.purge') }}" data-supplier-purge>
                        @csrf @method('DELETE')
                        <label>Confirm with your account password
                            <input name="password" type="password" autocomplete="current-password" placeholder="Enter your password" data-purge-password required>
                        </label>
                        <button class="delete-all-button" type="submit" data-purge-button>Clear published prices</button>
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

        <section class="panel imports-panel" aria-labelledby="import-archive-title">
            <div class="section-heading"><div><span class="section-kicker">IMPORT HISTORY</span><h2 id="import-archive-title">Imported price archive</h2></div></div>
            <p class="archive-note">Choose an upload date and file to view its saved prices. Newest imports appear first.</p>
            <form class="archive-filter" method="GET" action="{{ route('admin.suppliers') }}">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <label for="archive-import">Import date / file
                    <select id="archive-import" name="import" required @disabled($imports->isEmpty())>
                        <option value="">{{ $imports->isEmpty() ? 'No imports available' : 'Select an import' }}</option>
                        @foreach($imports->groupBy(fn ($import) => $import->created_at->format('M d, Y')) as $date => $datedImports)
                            <optgroup label="{{ $date }}">
                                @foreach($datedImports as $import)
                                    <option value="{{ $import->supplier_import_id }}" @selected($selectedImport?->supplier_import_id === $import->supplier_import_id)>{{ $import->created_at->format('h:i A') }} · {{ $import->supplier->name }} · {{ $import->source_filename }} · {{ $import->archived_at ? 'Archived '.$import->status : ucfirst($import->status) }} · #{{ $import->supplier_import_id }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </label>
                <button class="apply-button" type="submit" @disabled($imports->isEmpty())>Fetch records</button>
                @if($selectedImport)<a href="{{ route('admin.suppliers') }}">Close archive</a>@endif
            </form>
        </section>

        @if($selectedImport)
            <section class="panel preview-panel">
                <div class="section-heading">
                    <div><span class="section-kicker">{{ $selectedImport->status === 'pending' && ! $selectedImport->archived_at ? 'STEP 2 · REVIEW' : 'ARCHIVED IMPORT · READ ONLY' }}</span><h2>{{ $selectedImport->supplier->name }} — {{ $selectedImport->source_filename }}</h2></div>
                    <div class="import-heading-actions">
                        <span class="import-status {{ $selectedImport->error_count ? 'has-errors' : 'ready' }}">{{ $selectedImport->valid_count }} valid · {{ $selectedImport->error_count }} errors</span>
                        @if($selectedImport->status === 'pending' && ! $selectedImport->archived_at)
                            <button class="apply-button" type="button" data-open-import-decision>Review import decision</button>
                        @endif
                    </div>
                </div>
                <p class="archive-note">Uploaded {{ $selectedImport->created_at->format('M d, Y h:i A') }} · {{ $selectedImport->archived_at ? 'Archived '.strtolower($selectedImport->status) : ucfirst($selectedImport->status) }} · {{ $importRows->total() }} records. Prices below are from this import.</p>
                <div class="supplier-table-wrap">
                    <table>
                        <thead><tr><th>Row</th><th>Supplier SKU</th><th>Product</th><th>MotoSync match</th><th>Price</th><th>Availability</th><th>Status</th></tr></thead>
                        <tbody>
                        @foreach($importRows as $row)
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
                                        <span class="valid-label">{{ $selectedImport->status === 'pending' ? 'Ready' : ucfirst($selectedImport->status) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <nav class="archive-pagination" aria-label="Import records pagination">
                    <span>Showing {{ $importRows->firstItem() ?? 0 }}–{{ $importRows->lastItem() ?? 0 }} of {{ $importRows->total() }}</span>
                    @if($importRows->previousPageUrl())<a href="{{ $importRows->previousPageUrl() }}">Previous</a>@endif
                    @if($importRows->nextPageUrl())<a href="{{ $importRows->nextPageUrl() }}">Next</a>@endif
                </nav>
            </section>

            @if($selectedImport->status === 'pending' && ! $selectedImport->archived_at)
                <div class="supplier-modal" data-import-decision-modal hidden role="dialog" aria-modal="true" aria-labelledby="import-decision-title">
                    <div class="supplier-modal-card">
                        <button class="supplier-modal-close" type="button" data-close-modal aria-label="Close">×</button>
                        <span class="section-kicker">IMPORT DECISION</span>
                        <h2 id="import-decision-title">Accept or reject this supplier import?</h2>
                        <p><strong>{{ $selectedImport->supplier->name }}</strong> · {{ $selectedImport->source_filename }} · {{ $selectedImport->valid_count }} valid rows.</p>
                        @if($selectedImport->error_count > 0)<p class="modal-warning">Approval is unavailable because this import has {{ $selectedImport->error_count }} validation errors.</p>@endif
                        <div class="supplier-modal-actions">
                            <form method="POST" action="{{ route('admin.suppliers.imports.reject', $selectedImport) }}">@csrf<button class="reject-button" type="submit">Reject import</button></form>
                            <form method="POST" action="{{ route('admin.suppliers.imports.approve', $selectedImport) }}">@csrf<button class="apply-button" type="submit" @disabled($selectedImport->error_count > 0)>Accept and publish prices</button></form>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        <section class="panel suppliers-panel">
            <div class="section-heading">
                <div><span class="section-kicker">CURRENT DATA</span><h2>Published supplier prices</h2></div>
                <form class="supplier-sort-form" method="GET" action="{{ route('admin.suppliers') }}">
                    @if($selectedImport)<input type="hidden" name="import" value="{{ $selectedImport->supplier_import_id }}">@endif
                    <label for="supplier-sort">Sort prices
                        <select id="supplier-sort" name="sort">
                            <option value="updated_desc" @selected($sort === 'updated_desc')>Recently updated</option>
                            <option value="updated_asc" @selected($sort === 'updated_asc')>Oldest updated</option>
                            <option value="product" @selected($sort === 'product')>Product A–Z</option>
                            <option value="product_desc" @selected($sort === 'product_desc')>Product Z–A</option>
                            <option value="supplier" @selected($sort === 'supplier')>Supplier</option>
                            <option value="price_high" @selected($sort === 'price_high')>Price: high to low</option>
                            <option value="price_low" @selected($sort === 'price_low')>Price: low to high</option>
                            <option value="stock_high" @selected($sort === 'stock_high')>Supplier stock: high to low</option>
                            <option value="stock_low" @selected($sort === 'stock_low')>Supplier stock: low to high</option>
                        </select>
                    </label>
                    <button class="supplier-sort-button" type="submit">Sort</button>
                </form>
            </div>
            @if($prices->contains(fn ($price) => ! $price->product_id && strtoupper($price->currency) === 'PHP'))
                <div class="bulk-product-toolbar">
                    <div><strong>Bulk add to Products</strong><span>Select unmatched PHP supplier items below, then create them together.</span></div>
                    <div class="bulk-product-actions">
                        <label class="bulk-select-all"><input type="checkbox" data-bulk-select-all><span>Select all eligible</span></label>
                        <button class="apply-button" type="button" data-open-bulk-products disabled>Bulk add selected (<span data-bulk-count>0</span>)</button>
                    </div>
                </div>
            @endif
            <div class="pricing-list">
                @forelse($prices as $price)
                    @php
                        $change = $price->previous_price && (float) $price->previous_price > 0
                            ? (((float) $price->unit_price - (float) $price->previous_price) / (float) $price->previous_price) * 100
                            : null;
                        $isStale = $price->last_updated_at->lt(now()->subDays(30));
                        $comparison = $price->comparisonWithProduct();
                        $formatPesoCents = fn (int $cents) => ($cents < 0 ? '-₱' : '₱').number_format(abs($cents) / 100, 2);
                        $costDifference = $comparison['cost_difference_cents'] ?? null;
                        $projectedProfit = $comparison['projected_profit_cents'] ?? null;
                        $isAutomaticSkuMatch = $price->product
                            && mb_strtolower(trim($price->supplier_sku), 'UTF-8') === mb_strtolower(trim($price->product->sku), 'UTF-8');
                    @endphp
                    <article class="pricing-card">
                        @if(! $price->product_id && strtoupper($price->currency) === 'PHP')
                            <label class="bulk-product-select"><input type="checkbox" value="{{ $price->supplier_price_id }}" data-bulk-product-checkbox><span>Select</span></label>
                        @endif
                        <div class="supplier-line">
                            <strong>{{ $price->product_name }}</strong>
                            <small>{{ $price->supplier->name }} · Supplier SKU: {{ $price->supplier_sku }}</small>
                            <em class="{{ $price->product ? 'matched-text' : 'unmatched-text' }}">{{ $price->product ? '✓ '.($isAutomaticSkuMatch ? 'Automatically matched by SKU: ' : 'Matched to product ').$price->product->sku : 'Unmatched supplier item' }}</em>
                        </div>
                        <div class="pricing-pill">{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}<span>Supplier unit price</span></div>
                        <div class="pricing-pill">{{ $price->previous_price ? $price->currency.' '.number_format((float) $price->previous_price, 2) : 'First import' }}<span>Previous supplier price</span></div>
                        <div class="pricing-pill accent">{{ $change === null ? 'New' : sprintf('%+.1f%%', $change) }}<span>Supplier price change</span></div>
                        <div class="pricing-pill">{{ $price->available_quantity ?? 'Unknown' }}<span>Supplier stock</span></div>
                        <div class="pricing-pill {{ $isStale ? 'stale' : '' }}">{{ $isStale ? 'Stale' : $price->last_updated_at->diffForHumans() }}<span>Freshness</span></div>

                        @if($price->product)
                            <section class="price-match-panel matched" aria-label="Matched product price comparison">
                                <header>
                                    <div><span class="match-state">✓ PRODUCT MATCHED</span><strong>Supplier price compared with the recorded product cost</strong></div>
                                    <small>The selling price is shown separately and is never treated as the supplier cost.</small>
                                </header>
                                <div class="price-comparison-grid">
                                    <div><span>Product SKU</span><strong>{{ $price->product->sku }}</strong></div>
                                    <div><span>Model / Product Name</span><strong>{{ $price->product->name }}</strong>@if($price->product->manufacturer_part_number)<small>Part no. {{ $price->product->manufacturer_part_number }}</small>@endif</div>
                                    <div><span>Supplier Unit Price</span><strong>{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}</strong></div>
                                    <div><span>Product Unit Cost</span><strong>₱{{ number_format((float) $price->product->unit_cost, 2) }}</strong></div>
                                    <div><span>Product Selling Price</span><strong>₱{{ number_format((float) $price->product->unit_price, 2) }}</strong></div>
                                    <div class="{{ $costDifference === null ? 'comparison-warning' : ($costDifference > 0 ? 'comparison-higher' : ($costDifference < 0 ? 'comparison-lower' : 'comparison-equal')) }}">
                                        <span>Cost Difference</span>
                                        <strong>
                                            @if($costDifference === null)
                                                Currency conversion required
                                            @elseif($costDifference === 0)
                                                ₱0.00 · Same cost
                                            @else
                                                {{ $formatPesoCents($costDifference) }} · {{ $costDifference > 0 ? 'Supplier is higher' : 'Supplier is lower' }}
                                            @endif
                                        </strong>
                                        @if($projectedProfit !== null)<small>Profit if applied: {{ $formatPesoCents($projectedProfit) }} per unit</small>@endif
                                    </div>
                                </div>
                            </section>
                        @else
                            <section class="price-match-panel unmatched" aria-label="Unmatched supplier item">
                                <span class="match-state">! NOT MATCHED</span>
                                <strong>No product price comparison is available.</strong>
                                <small>Match this supplier item to the correct SKU or create it in Products before comparing or applying its cost.</small>
                            </section>
                        @endif
                        <details class="catalog-sync">
                            <summary>{{ $price->product ? 'Manage product match and cost' : 'Match or add this item to Products' }}</summary>
                            <div class="catalog-sync-body">
                                <div class="sync-explanation">
                                    <strong>{{ $price->product ? 'Currently matched to '.$price->product->sku.' — '.$price->product->name : 'This supplier item is not connected to Products yet.' }}</strong>
                                    <span>Supplier stock is informational and will never be added to store inventory automatically.</span>
                                </div>

                                @if(! $isAutomaticSkuMatch)
                                    <form class="match-product-form" method="POST" action="{{ route('admin.suppliers.prices.match', $price) }}" data-catalog-match-form>
                                        @csrf @method('PATCH')
                                        <label>Match an existing product
                                            <input type="search" list="catalog-product-options" placeholder="Search SKU or product name" autocomplete="off" data-catalog-match-search required>
                                            <input type="hidden" name="product_id" data-catalog-product-id>
                                        </label>
                                        <button class="match-button" type="submit">Save match</button>
                                    </form>
                                @else
                                    <div class="automatic-match-note"><strong>No manual matching needed</strong><span>The supplier SKU and product SKU are the same.</span></div>
                                @endif

                                @if($price->product)
                                    <form class="unmatch-product-form" method="POST" action="{{ route('admin.suppliers.prices.unmatch', $price) }}" data-unmatch-product data-supplier-item="{{ $price->product_name }}" data-product-name="{{ $price->product->sku }} — {{ $price->product->name }}">
                                        @csrf @method('DELETE')
                                        <span><strong>Wrong product match?</strong><small>Remove only this connection. Product pricing and inventory will stay unchanged.</small></span>
                                        <button class="unmatch-button" type="submit">Unmatch product</button>
                                    </form>
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
                                        <label>Manufacturer part number (required)<input name="manufacturer_part_number" maxlength="150" required placeholder="Official number from manufacturer or packaging"></label>
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

        <div class="supplier-modal" data-bulk-products-modal hidden role="dialog" aria-modal="true" aria-labelledby="bulk-products-title">
            <div class="supplier-modal-card bulk-modal-card">
                <button class="supplier-modal-close" type="button" data-close-modal aria-label="Close">×</button>
                <span class="section-kicker">BULK PRODUCT CREATION</span>
                <h2 id="bulk-products-title">Add selected supplier items to Products?</h2>
                <p><strong data-bulk-modal-count>0</strong> products will be created with their supplier SKU as the product SKU and part number. Store stock starts at zero.</p>
                <form class="bulk-product-form" method="POST" action="{{ route('admin.suppliers.prices.bulk-create-products') }}" data-bulk-product-form>
                    @csrf
                    <div data-bulk-product-ids></div>
                    <label>Selling price markup (%)<input name="markup_percent" type="number" min="0" max="1000" step="0.01" value="30" required></label>
                    <label>Category<input name="category" maxlength="100" placeholder="e.g. Supplier Import"></label>
                    <label>Shelf location<input name="shelf_location" maxlength="100" placeholder="Optional default location"></label>
                    <label>Reorder level<input name="reorder_level" type="number" min="0" value="5" required></label>
                    <div class="supplier-modal-actions"><button class="modal-cancel-button" type="button" data-close-modal>Cancel</button><button class="apply-button" type="submit">Create selected products</button></div>
                </form>
            </div>
        </div>

        <datalist id="catalog-product-options">
            @foreach($catalogProducts as $catalogProduct)
                <option value="{{ $catalogProduct->sku }} — {{ $catalogProduct->name }} (#{{ $catalogProduct->product_id }})" data-product-id="{{ $catalogProduct->product_id }}"></option>
            @endforeach
        </datalist>

        <section class="panel imports-panel">
            <div class="section-heading"><div><span class="section-kicker">AUDIT TRAIL</span><h2>Recent imports</h2></div></div>
            <div class="import-list">
                @forelse($imports->take(10) as $import)
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
