@php
$navigation = [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Supplier Price | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/suppliers.css','resources/css/sorting-controls.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/suppliers.js'])
    @include('partials.appearance')
</head>
<body data-supplier-selection-owner="{{ auth()->id() }}">
@if(session('bulk_review_finished'))<span hidden data-bulk-review-finished></span>@endif
<div class="dashboard-shell suppliers-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 8 ? 'active' : '' }}" href="{{ url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name, 0, 2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main suppliers-main">
        <header class="suppliers-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
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

        <section class="stat-grid suppliers-stats" id="supplier-price-summary" data-table-summary-for="supplier-prices" aria-label="Supplier price summary">
            <article class="stat-card purple"><div class="stat-head"><span>ACTIVE SUPPLIERS</span></div><strong>{{ $summary['suppliers'] }}</strong><small>With imported pricing</small></article>
            <article class="stat-card violet"><div class="stat-head"><span>PUBLISHED PRICES</span></div><strong>{{ $summary['prices'] }}</strong><small>Current supplier SKUs</small></article>
            <article class="stat-card orange"><div class="stat-head"><span>PRICE CHANGES</span></div><strong>{{ $summary['changes'] }}</strong><small>Changed since previous import</small></article>
            <article class="stat-card red"><div class="stat-head"><span>STALE PRICES</span></div><strong>{{ $summary['stale'] }}</strong><small>Not updated for 30 days</small></article>
        </section>

        @if($summary['prices'])
            <div class="supplier-purge-launch">
                <button class="delete-all-button" type="button" data-open-supplier-purge aria-haspopup="dialog" aria-controls="supplier-purge-dialog"><i class="bi bi-trash3" aria-hidden="true"></i> Clear published supplier prices</button>
            </div>
            <dialog class="supplier-price-dialog supplier-purge-dialog" id="supplier-purge-dialog" aria-labelledby="supplier-purge-title">
                <header class="supplier-price-dialog-header"><h2 id="supplier-purge-title">Clear published supplier prices</h2><button type="button" data-close-supplier-purge aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
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
                        <div class="supplier-purge-actions"><button class="modal-cancel-button" type="button" data-close-supplier-purge>Cancel</button><button class="delete-all-button" type="submit" data-purge-button>Clear published prices</button></div>
                    </form>
                </div>
            </dialog>
        @endif

        <section class="panel import-panel">
            <div class="section-heading">
                <div><span class="section-kicker">STEP 1</span><h2>Upload supplier price list</h2></div>
            </div>
            <form class="supplier-import-form" method="POST" action="{{ route('admin.suppliers.imports.upload') }}" enctype="multipart/form-data" data-supplier-upload-form>
                @csrf
                <label>Supplier name<input name="supplier_name" value="{{ old('supplier_name') }}" required maxlength="150" placeholder="ABC Motor Parts"></label>
                <label>Supplier code<input name="supplier_code" value="{{ old('supplier_code') }}" required maxlength="50" pattern="[A-Za-z0-9_-]+" placeholder="ABC-MOTOR"></label>
                <label>Import method<select name="import_mode" data-supplier-import-mode><option value="spreadsheet" @selected(old('import_mode', 'spreadsheet') === 'spreadsheet')>Automatic CSV / XLSX</option><option value="ai" @selected(old('import_mode') === 'ai')>AI document extraction</option></select></label>
                <label data-ai-import-settings @if(old('import_mode') !== 'ai') hidden @endif>Currency if not stated<input name="default_currency" value="{{ old('default_currency', 'PHP') }}" maxlength="3" pattern="[A-Za-z]{3}" placeholder="PHP"></label>
                <label class="file-field">Price-list file<input name="price_file" type="file" accept=".csv,.xlsx" required data-supplier-file><span data-file-name>Choose CSV or XLSX file</span></label>
                <button class="apply-button" type="submit">Upload and preview</button>
            </form>
            <p class="import-note" data-ai-import-note @if(old('import_mode') !== 'ai') hidden @endif><i class="bi bi-stars" aria-hidden="true"></i> AI reads SKU, product name, and supplier unit price from PDF, DOCX, TXT, CSV, XLSX, PNG, JPG, or WEBP. Large CSV and XLSX files are processed in batches. This sends your document to the configured OpenAI service. Review the detected rows and correct missing or uncertain values before publishing.</p>
            <div class="format-guide">
                <strong>Standard field names (automatically converted):</strong>
                <code>product_name</code><code>unit_price</code>
                <strong>Optional:</strong>
                <code>supplier_sku</code><code>internal_sku</code><code>currency</code><code>available_quantity</code><code>minimum_order_quantity</code><code>lead_time_days</code><code>effective_date</code>
            </div>
        </section>

        <section class="panel imports-panel" aria-labelledby="import-archive-title">
            <div class="section-heading"><div><span class="section-kicker">IMPORT HISTORY</span><h2 id="import-archive-title">Imported price archive</h2></div></div>
            <p class="archive-note">Choose an upload date and file to view its saved prices. Newest imports appear first.</p>
            <form class="archive-filter" method="GET" action="{{ route('admin.suppliers') }}">
                <input type="hidden" name="sort" value="{{ $sort }}">
                <input type="hidden" name="search" value="{{ $search }}">
                <input type="hidden" name="per_page" value="{{ $perPage }}">
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
                <label for="archive-search">Search imported prices
                    <input id="archive-search" type="search" name="import_search" value="{{ $importSearch }}" placeholder="Product name, supplier SKU, or internal SKU" @disabled($imports->isEmpty())>
                </label>
                <button class="apply-button" type="submit" @disabled($imports->isEmpty())>Fetch records</button>
                @if($selectedImport && $importSearch !== '')<a href="{{ route('admin.suppliers', ['import' => $selectedImport->supplier_import_id, 'sort' => $sort, 'search' => $search]) }}">Clear search</a>@endif
                @if($selectedImport)<a href="{{ route('admin.suppliers') }}">Close archive</a>@endif
            </form>
        </section>

        @if($selectedImport)
            <section class="panel preview-panel" id="import-records">
                <div class="section-heading">
                    <div><span class="section-kicker">{{ $selectedImport->status === 'pending' && ! $selectedImport->archived_at ? 'STEP 2 · REVIEW' : 'ARCHIVED IMPORT · READ ONLY' }}</span><h2>{{ $selectedImport->supplier->name }} — {{ $selectedImport->source_filename }}</h2></div>
                    <div class="import-heading-actions">
                        <span class="import-status {{ $selectedImport->error_count ? 'has-errors' : 'ready' }}">{{ $selectedImport->valid_count }} valid · {{ $selectedImport->error_count }} errors</span>
                        @if($selectedImport->status === 'pending' && ! $selectedImport->archived_at)
                            <button class="apply-button" type="button" data-open-import-decision><i class="bi bi-clipboard-check" aria-hidden="true"></i> Review import decision <i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                        @elseif($selectedImport->status === 'approved' && ! $selectedImport->archived_at)
                            <a class="apply-button" href="{{ route('admin.suppliers.imports.stock-review', $selectedImport) }}" data-open-stock-review>Review stock additions</a>
                        @endif
                    </div>
                </div>
                <p class="archive-note">Uploaded {{ $selectedImport->created_at->format('M d, Y h:i A') }} · {{ $selectedImport->archived_at ? 'Archived '.strtolower($selectedImport->status) : ucfirst($selectedImport->status) }} · {{ $importRows->total() }} {{ $importSearch !== '' ? 'matching records' : 'records' }}. Prices below are from this import. @if($selectedImport->extraction_method === 'ai')<strong><i class="bi bi-stars" aria-hidden="true"></i> AI extracted · verify against your original document</strong>@endif</p>
                <div class="supplier-table-wrap">
                    <table>
                        <thead><tr><th>Row</th><th>Supplier SKU</th><th>Product</th><th>MotoSync match</th><th>Price</th><th>Availability</th><th>Status</th><th>Review</th></tr></thead>
                        <tbody>
                        @forelse($importRows as $row)
                            <tr class="{{ $row->validation_errors ? 'row-error' : '' }}">
                                <td>{{ $row->row_number }}</td>
                                <td>{{ $row->supplier_sku }}</td>
                                <td>{{ $row->product_name }}@if($row->source_reference)<small>Source: {{ $row->source_reference }}</small>@endif</td>
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
                                <td>
                                    @if($selectedImport->status === 'pending' && !$selectedImport->archived_at)
                                        <button class="apply-button" type="button" data-open-row-review="import-row-{{ $row->supplier_import_row_id }}" aria-haspopup="dialog">Review / correct</button>
                                        <dialog class="supplier-price-dialog import-row-dialog" id="import-row-{{ $row->supplier_import_row_id }}" data-import-row-dialog data-open-on-error="{{ $errors->any() && (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? 'true' : 'false' }}" aria-labelledby="import-row-title-{{ $row->supplier_import_row_id }}">
                                            <header class="supplier-price-dialog-header"><h2 id="import-row-title-{{ $row->supplier_import_row_id }}">Review import row {{ $row->row_number }}</h2><button type="button" data-close-row-review aria-label="Close row review"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
                                            @if($row->validation_errors)<div class="supplier-message error"><ul>@foreach($row->validation_errors as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                                            @if($errors->any() && (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id)<div class="supplier-message error" role="alert">{{ $errors->first() }}</div>@endif
                                            <form method="POST" action="{{ route('admin.suppliers.imports.rows.update', [$selectedImport, $row]) }}" class="import-row-form">
                                                @csrf @method('PATCH')
                                                <input type="hidden" name="_import_row_dialog" value="{{ $row->supplier_import_row_id }}">
                                                <label>Supplier SKU<input name="supplier_sku" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('supplier_sku') : $row->supplier_sku }}" maxlength="255" required></label>
                                                <label>Product name<input name="product_name" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('product_name') : $row->product_name }}" maxlength="255" required></label>
                                                <label>Supplier unit price<input name="unit_price" type="number" min="0.01" max="9999999999.99" step="0.01" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('unit_price') : $row->unit_price }}" required></label>
                                                <label>Currency<input name="currency" maxlength="3" pattern="[A-Za-z]{3}" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('currency') : $row->currency }}" required></label>
                                                <label>Manufacturer part number<input name="manufacturer_part_number" maxlength="150" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('manufacturer_part_number') : $row->manufacturer_part_number }}"></label>
                                                <label>Units received by shop<input type="number" name="shop_received_quantity" min="0" max="2147483647" value="{{ (string) old('_import_row_dialog') === (string) $row->supplier_import_row_id ? old('shop_received_quantity') : $row->shop_received_quantity }}"></label>
                                                <label class="import-review-confirm"><input type="checkbox" name="confirm_review" value="1" required> I checked these values against the original supplier document.</label>
                                                <button class="apply-button" type="submit">Save reviewed row</button>
                                            </form>
                                        </dialog>
                                    @else
                                        Read only
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="empty-state">{{ $importSearch !== '' ? 'No imported prices match your search. Try another product name or SKU.' : 'No records in this import.' }}</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                @include('partials.ml-table-pagination', ['rows' => $importRows, 'sizeName' => 'import_rows', 'pageName' => 'import_page', 'anchor' => 'import-records', 'tableLabel' => 'Import records'])
            </section>

            @if($selectedImport->status === 'pending' && ! $selectedImport->archived_at)
                <div class="supplier-modal" data-import-decision-modal hidden role="dialog" aria-modal="true" aria-labelledby="import-decision-title">
                    <div class="supplier-modal-card">
                        <button class="supplier-modal-close" type="button" data-close-modal aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        <span class="section-kicker">IMPORT DECISION</span>
                        <h2 id="import-decision-title">Accept or reject this supplier import?</h2>
                        <p><strong>{{ $selectedImport->supplier->name }}</strong> · {{ $selectedImport->source_filename }} · {{ $selectedImport->valid_count }} valid rows.</p>
                        <p>Rejecting this import permanently removes it and its rows from the import archive.</p>
                        @if($selectedImport->error_count > 0)<p class="modal-warning">Approval is unavailable because this import has {{ $selectedImport->error_count }} validation errors.</p>@endif
                        <div class="supplier-modal-actions">
                            <form method="POST" action="{{ route('admin.suppliers.imports.reject', $selectedImport) }}">@csrf<button class="reject-button" type="submit">Reject import</button></form>
                            <form method="POST" action="{{ route('admin.suppliers.imports.approve', $selectedImport) }}">@csrf<button class="apply-button" type="submit" @disabled($selectedImport->error_count > 0)>Accept and publish prices</button></form>
                        </div>
                    </div>
                </div>
            @endif
        @endif

        <section class="panel suppliers-panel" id="supplier-prices" data-published-price-list="{{ $publishedPriceList }}">
            <div class="section-heading">
                <div>
                    <span class="section-kicker">CURRENT DATA</span><h2>Published supplier prices</h2>
                    @if($publishedPriceList)
                        <a href="{{ route('admin.suppliers.imports.stock-review', $publishedPriceList) }}" data-open-stock-review>Review stock from latest import</a>
                    @endif
                </div>
                <form class="supplier-sort-form" method="GET" action="{{ route('admin.suppliers') }}">
                    @if($selectedImport)<input type="hidden" name="import" value="{{ $selectedImport->supplier_import_id }}">@endif
                    @if($importSearch !== '')<input type="hidden" name="import_search" value="{{ $importSearch }}">@endif
                    <label for="supplier-per-page">Rows per page
                        <select id="supplier-per-page" name="per_page" data-supplier-page-size>@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }} rows</option>@endforeach</select>
                    </label>
                    <label class="supplier-price-search" for="supplier-price-search">Search products
                        <input id="supplier-price-search" name="search" type="search" value="{{ $search }}" placeholder="Product, SKU, category, part number, or supplier">
                    </label>
                    <label for="supplier-sort">Sort
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
                    <button class="supplier-sort-button" type="submit">Search &amp; sort</button>
                    @if($search)<a class="supplier-search-clear" href="{{ route('admin.suppliers', array_filter(['sort' => $sort, 'per_page' => $perPage, 'import' => $selectedImport?->supplier_import_id, 'import_search' => $importSearch])) }}">Clear</a>@endif
                </form>
            </div>
                <div class="bulk-product-toolbar">
                    <div><strong>Bulk add to Products</strong><span>Select unmatched PHP supplier items across pages, then create them together. Selections stay while browsing this table.</span></div>
                    <div class="bulk-product-actions">
                        <label class="bulk-select-all"><input type="checkbox" data-bulk-select-all><span>Select all eligible on this page</span></label>
                        <button class="apply-button" type="button" data-open-bulk-products data-selection-url="{{ route('admin.suppliers.bulk.selection') }}" disabled>Bulk add selected (<span data-bulk-count>0</span>)</button>
                    </div>
                </div>
            <div class="supplier-table-wrap published-price-table">
                <table>
                    <thead><tr><th>Select</th><th>Product / SKU</th><th>Supplier</th><th>Unit price</th><th>Supplier stock</th><th>Product match</th><th>Updated</th><th>Actions</th></tr></thead>
                    <tbody>
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
                    <tr>
                        <td>
                        @if(! $price->product_id && strtoupper($price->currency) === 'PHP')
                            <input type="checkbox" value="{{ $price->supplier_price_id }}" data-bulk-product-checkbox data-product-name="{{ $price->product_name }}" data-product-sku="{{ $price->supplier_sku }}" aria-label="Select {{ $price->product_name }} for bulk product creation">
                        @else
                            <span class="selection-unavailable" aria-label="Not eligible for bulk product creation">—</span>
                        @endif
                        </td>
                        <td><strong>{{ $price->product_name }}</strong><small>{{ $price->supplier_sku }}</small></td>
                        <td>{{ $price->supplier->name }}</td>
                        <td>{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}</td>
                        <td>{{ $price->available_quantity ?? 'Unknown' }}</td>
                        <td><span class="match-label {{ $price->product ? 'matched' : 'unmatched' }}">{{ $price->product ? 'Matched: '.$price->product->sku : 'Unmatched' }}</span></td>
                        <td>{{ $isStale ? 'Stale · ' : '' }}{{ $price->last_updated_at->diffForHumans() }}</td>
                        <td>
                            <button class="supplier-price-manage apply-button" type="button" data-open-price-dialog="supplier-price-{{ $price->supplier_price_id }}" aria-haspopup="dialog" aria-controls="supplier-price-{{ $price->supplier_price_id }}"><i class="bi bi-eye" aria-hidden="true"></i> View / manage</button>
                            <dialog class="supplier-price-dialog" id="supplier-price-{{ $price->supplier_price_id }}" data-supplier-price-dialog data-open-on-error="{{ $errors->any() && (string) old('_supplier_price_dialog') === (string) $price->supplier_price_id ? 'true' : 'false' }}" data-price-id="{{ $price->supplier_price_id }}" aria-labelledby="supplier-price-title-{{ $price->supplier_price_id }}">
                                <header class="supplier-price-dialog-header"><h2 id="supplier-price-title-{{ $price->supplier_price_id }}">Supplier price details</h2><button type="button" data-close-price-dialog aria-label="Close supplier price details"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
                                @if($errors->any() && (string) old('_supplier_price_dialog') === (string) $price->supplier_price_id)<div class="supplier-message error" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <article class="pricing-card">
                        <div class="supplier-line">
                            <strong>{{ $price->product_name }}</strong>
                            <small>{{ $price->supplier->name }} · Supplier SKU: {{ $price->supplier_sku }}</small>
                            <em class="{{ $price->product ? 'matched-text' : 'unmatched-text' }}"><i class="bi bi-{{ $price->product ? 'check-circle' : 'question-circle' }}" aria-hidden="true"></i> {{ $price->product ? ($isAutomaticSkuMatch ? 'Automatically matched by SKU: ' : 'Matched to product ').$price->product->sku : 'Unmatched supplier item' }}</em>
                        </div>
                        <div class="pricing-pill">{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}<span>Supplier unit price</span></div>
                        <div class="pricing-pill">{{ $price->previous_price ? $price->currency.' '.number_format((float) $price->previous_price, 2) : 'First import' }}<span>Previous supplier price</span></div>
                        <div class="pricing-pill accent">{{ $change === null ? 'New' : sprintf('%+.1f%%', $change) }}<span>Supplier price change</span></div>
                        <div class="pricing-pill">{{ $price->available_quantity ?? 'Unknown' }}<span>Supplier stock</span></div>
                        <div class="pricing-pill">{{ $price->shop_received_quantity ?? 'Not supplied' }}<span>Imported shop opening stock</span></div>
                        <div class="pricing-pill {{ $isStale ? 'stale' : '' }}">{{ $isStale ? 'Stale' : $price->last_updated_at->diffForHumans() }}<span>Freshness</span></div>

                        @if($price->product)
                            <section class="price-match-panel matched" aria-label="Matched product price comparison">
                                <header>
                                    <div><span class="match-state"><i class="bi bi-check-circle" aria-hidden="true"></i> PRODUCT MATCHED</span><strong>Supplier price compared with the recorded product cost</strong></div>
                                    <small>The selling price is shown separately and is never treated as the supplier cost.</small>
                                </header>
                                <div class="price-comparison-grid">
                                    <div><span>Product SKU</span><strong>{{ $price->product->sku }}</strong></div>
                                    <div><span>Model / Product Name</span><strong>{{ $price->product->name }}</strong>@if($price->product->manufacturer_part_number)<small>Part no. {{ $price->product->manufacturer_part_number }}</small>@endif</div>
                                    <div><span>Supplier Unit Price</span><strong>{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}</strong></div>
                                    <div><span>Product Unit Cost</span><strong>{{ \App\Support\Display::money((float) $price->product->unit_cost) }}</strong></div>
                                    <div><span>Product Selling Price</span><strong>{{ \App\Support\Display::money((float) $price->product->unit_price) }}</strong></div>
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
                        <section class="catalog-sync">
                            <h3>{{ $price->product ? 'Manage product match and cost' : 'Match or add this item to Products' }}</h3>
                            <div class="catalog-sync-body">
                                <div class="sync-explanation">
                                    <strong>{{ $price->product ? 'Currently matched to '.$price->product->sku.' — '.$price->product->name : 'This supplier item is not connected to Products yet.' }}</strong>
                                    <span>Review the units your shop received before adding them to existing stock.</span>
                                </div>

                                @if(! $isAutomaticSkuMatch)
                                    <form class="match-product-form" method="GET" action="{{ route('admin.suppliers.prices.stock-review', $price) }}" data-catalog-match-form>
                                        <label>Match an existing product
                                            <input type="search" list="catalog-product-options" placeholder="Search SKU or product name" autocomplete="off" data-catalog-match-search required>
                                            <input type="hidden" name="product_id" data-catalog-product-id>
                                        </label>
                                        <button class="match-button" type="submit">Review match</button>
                                    </form>
                                @else
                                    <div class="automatic-match-note"><strong>No manual matching needed</strong><span>The supplier SKU and product SKU are the same.</span></div>
                                @endif

                                @if($price->product)
                                    @if($price->product->is_active && ! $price->stock_applied_at)
                                        <div><a class="apply-button" href="{{ route('admin.suppliers.prices.stock-review', $price) }}" data-open-stock-review>Review stock addition</a></div>
                                    @endif
                                    <form class="unmatch-product-form" method="POST" action="{{ route('admin.suppliers.prices.unmatch', $price) }}" data-unmatch-product data-supplier-item="{{ $price->product_name }}" data-product-name="{{ $price->product->sku }} — {{ $price->product->name }}">
                                        @csrf @method('DELETE')
                                        <span><strong>Wrong product match?</strong><small>Remove only this connection. Product pricing and inventory will stay unchanged.</small></span>
                                        <button class="unmatch-button" type="submit">Unmatch product</button>
                                    </form>
                                    <form class="apply-cost-form" method="POST" action="{{ route('admin.suppliers.prices.apply-cost', $price) }}" data-apply-supplier-cost data-product-name="{{ $price->product->name }}" data-cost="{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}">
                                        @csrf @method('PATCH')
                                        <div><small>Current product cost</small><strong>{{ \App\Support\Display::money((float) $price->product->unit_cost) }}</strong></div>
                                        <div><small>Supplier cost to apply</small><strong>{{ $price->currency }} {{ number_format((float) $price->unit_price, 2) }}</strong></div>
                                        <button class="apply-button" type="submit" @disabled(strtoupper($price->currency) !== 'PHP')>Apply supplier cost</button>
                                    </form>
                                @else
                                    <form class="create-catalog-product" method="POST" action="{{ route('admin.suppliers.prices.create-product', $price) }}">
                                        @csrf
                                        <label>Product SKU<input name="sku" value="{{ $price->supplier_sku }}" maxlength="100" required></label>
                                        <label>Manufacturer part number (required)<input name="manufacturer_part_number" maxlength="150" required placeholder="Official number from manufacturer or packaging"></label>
                                        <label>Selling price (₱)<input name="selling_price" type="number" min="0" step="0.01" placeholder="Set owner selling price" required></label>
                                        @include('admin.partials.supplier-catalog-options')
                                        <label>Reorder level<input name="reorder_level" type="number" min="0" value="5" required></label>
                                        @php($openingStock = $price->available_quantity ?? $price->shop_received_quantity ?? 0)
                                        <label>Opening stock<input name="initial_stock" type="number" min="0" max="2147483647" step="1" value="{{ (string) old('_supplier_price_dialog') === (string) $price->getKey() ? old('initial_stock', $openingStock) : $openingStock }}" required><small>Confirm the units your shop has on hand before creating the product.</small></label>
                                        <button class="apply-button" type="submit" @disabled(strtoupper($price->currency) !== 'PHP')>Create in Products</button>
                                    </form>
                                @endif

                                @if(strtoupper($price->currency) !== 'PHP')
                                    <p class="currency-warning">Convert this supplier price to PHP before applying it to the product catalog.</p>
                                @endif
                            </div>
                        </section>
                    </article>
                                <footer class="supplier-price-dialog-footer"><button class="modal-cancel-button" type="button" data-close-price-dialog>Close</button></footer>
                            </dialog>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="empty-state">{{ $search ? 'No supplier prices match “'.$search.'”. Try a product name, SKU, category, part number, or supplier.' : 'No supplier prices have been published. Upload a price list to begin.' }}</td></tr>
                @endforelse
                    </tbody>
                </table>
            </div>
            @include('partials.ml-table-pagination', ['rows' => $prices, 'sizeName' => 'per_page', 'pageName' => 'prices_page', 'anchor' => 'supplier-prices', 'tableLabel' => 'Supplier prices'])
        </section>

        <div class="supplier-modal" data-bulk-products-modal hidden role="dialog" aria-modal="true" aria-labelledby="bulk-products-title">
            <div class="supplier-modal-card bulk-modal-card">
                <button class="supplier-modal-close" type="button" data-close-modal aria-label="Close"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                <span class="section-kicker">BULK PRODUCT CREATION</span>
                <h2 id="bulk-products-title">Add selected supplier items to Products?</h2>
                <p><strong data-bulk-modal-count>0</strong> selected items</p>
                <form method="POST" action="{{ route('admin.suppliers.bulk.start') }}" data-bulk-review-launch>
                    @csrf
                    <input type="hidden" name="popup" value="0">
                    <div data-bulk-product-ids></div>
                    <div class="supplier-modal-actions"><button class="modal-cancel-button" type="button" data-close-modal>Cancel</button><button class="apply-button" type="submit">Review products</button></div>
                </form>
            </div>
        </div>

        <dialog class="supplier-review-dialog" data-bulk-review-dialog aria-label="Review supplier products">
            <button class="apply-button" type="button" data-close-bulk-review>Close review</button>
            <iframe name="supplier-bulk-review" title="Review supplier products"></iframe>
        </dialog>

        <dialog class="supplier-price-dialog stock-review-dialog" data-stock-review-dialog aria-labelledby="stock-review-title">
            <header class="supplier-price-dialog-header">
                <h2 id="stock-review-title">Review supplier stock</h2>
                <button type="button" data-close-stock-review aria-label="Close stock review"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </header>
            <p data-stock-review-status role="status" hidden></p>
            <button class="apply-button" type="button" data-refresh-stock-review hidden>Refresh stock review</button>
            <div data-stock-review-body></div>
        </dialog>

        <datalist id="catalog-product-options">
            @foreach($catalogProducts as $catalogProduct)
                <option value="{{ $catalogProduct->sku }} — {{ $catalogProduct->name }} (#{{ $catalogProduct->product_id }})" data-product-id="{{ $catalogProduct->product_id }}"></option>
            @endforeach
        </datalist>

        <section class="panel imports-panel" id="import-history" data-history-panel="Import audit trail" data-history-page="history_page" data-history-size="history_rows" hidden>
            <div class="section-heading"><div><span class="section-kicker">AUDIT TRAIL</span><h2>Recent imports</h2></div></div>
            <div class="import-list">
                @forelse($importHistory as $import)
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
        @include('partials.ml-table-pagination', ['rows' => $importHistory, 'sizeName' => 'history_rows', 'pageName' => 'history_page', 'anchor' => 'import-history', 'tableLabel' => 'Import audit trail'])
</section>
    </main>
</div>
@include('partials.login-stock-alert')
</body>
</html>
