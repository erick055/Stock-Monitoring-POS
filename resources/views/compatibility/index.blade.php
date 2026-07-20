@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['◎','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','#'],
] : [
    ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','#'],
];
$checkerRoute = $isAdmin ? route('admin.compatibility') : route('staff.compatibility');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Motorcycle Parts Compatibility Checker | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/compatibility.css','resources/js/dashboard.js','resources/js/compatibility.js'])
</head>
<body>
<div class="dashboard-shell compatibility-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === count($navigation) - 1 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main compatibility-main">
        <header class="compatibility-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div>
                <p class="welcome">EVIDENCE-BASED FITMENT DECISION SUPPORT</p>
                <h1>AI Motorcycle Parts Compatibility Checker</h1>
                <p>Verified fitment first, technical rules second, AI-assisted descriptions only when structured data is missing.</p>
            </div>
        </header>

        @if(session('success'))
            <div class="compatibility-message success" role="status">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="compatibility-message error" role="alert">
                <strong>Please correct the form:</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="panel search-panel">
            <div class="section-heading">
                <div><span class="section-kicker">COMPATIBILITY CHECK</span><h2>Enter motorcycle details</h2></div>
                <span class="evidence-pill">Safety-first results</span>
            </div>
            <form class="checker-form" method="GET" action="{{ $checkerRoute }}">
                <input type="hidden" name="vehicle_search" value="1">
                <label class="catalog-selector">
                    Choose a verified motorcycle profile
                    <select name="motorcycle_id" data-motorcycle-profile>
                        <option value="">Enter motorcycle details manually</option>
                        @foreach($motorcycles as $motorcycle)
                            <option
                                value="{{ $motorcycle->motorcycle_id }}"
                                data-brand="{{ $motorcycle->brand }}"
                                data-model="{{ $motorcycle->model }}"
                                data-year="{{ $motorcycle->year }}"
                                data-engine="{{ $motorcycle->engine }}"
                                data-variant="{{ $motorcycle->variant }}"
                                @selected($vehicleInput['motorcycle_id'] === (string) $motorcycle->motorcycle_id)
                            >{{ $motorcycle->display_name }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="vehicle-search-grid">
                    <label>Brand<input name="brand" list="compatibility-brands" value="{{ $vehicleInput['brand'] }}" placeholder="Honda"></label>
                    <label>Model<input name="model" list="compatibility-models" value="{{ $vehicleInput['model'] }}" placeholder="Click 160"></label>
                    <label>Year<input name="year" type="number" min="1950" max="{{ now()->year + 2 }}" value="{{ $vehicleInput['year'] }}" placeholder="2025"></label>
                    <label>Engine<input name="engine" list="compatibility-engines" value="{{ $vehicleInput['engine'] }}" placeholder="157cc"></label>
                    <label>Variant (optional)<input name="variant" list="compatibility-variants" value="{{ $vehicleInput['variant'] }}" placeholder="ABS / Standard"></label>
                </div>
                <div class="checker-actions">
                    <label>Part name, SKU or category (optional)<input name="part_search" type="search" value="{{ request('part_search') }}" placeholder="Leave blank to check all products"></label>
                    <button class="search-action" type="submit">Search Compatible Parts</button>
                </div>
                <datalist id="compatibility-brands">@foreach($motorcycles->pluck('brand')->unique() as $value)<option value="{{ $value }}">@endforeach</datalist>
                <datalist id="compatibility-models">@foreach($motorcycles->pluck('model')->unique() as $value)<option value="{{ $value }}">@endforeach</datalist>
                <datalist id="compatibility-engines">@foreach($motorcycles->pluck('engine')->unique() as $value)<option value="{{ $value }}">@endforeach</datalist>
                <datalist id="compatibility-variants">@foreach($motorcycles->pluck('variant')->filter()->unique() as $value)<option value="{{ $value }}">@endforeach</datalist>
            </form>
            <p class="form-hint">This is a one-time check. The motorcycle details entered here are not saved as a customer vehicle.</p>
        </section>

        @if($selectedMotorcycle)
            <div class="catalog-message {{ $catalogMatched ? 'matched' : 'unmatched' }}">
                @if($catalogMatched)
                    Verified reference profile found. Confirmed fitment records and technical rules are available.
                @else
                    No exact reference profile was found. AI-assisted text hints may be shown, but results cannot be marked confirmed.
                @endif
            </div>
            <section class="vehicle-strip" aria-label="Selected motorcycle details">
                <article><span>Brand</span><strong>{{ $selectedMotorcycle->brand }}</strong></article>
                <article><span>Model</span><strong>{{ $selectedMotorcycle->model }}</strong></article>
                <article><span>Year</span><strong>{{ $selectedMotorcycle->year }}</strong></article>
                <article><span>Engine</span><strong>{{ $selectedMotorcycle->engine }}</strong></article>
                <article><span>Variant</span><strong>{{ $selectedMotorcycle->variant ?: 'Standard / not specified' }}</strong></article>
            </section>

            <section class="result-summary" aria-label="Compatibility result summary">
                <article class="summary-recommended"><span>Recommended</span><strong>{{ $summary['recommended'] }}</strong></article>
                <article class="summary-confirmed"><span>Confirmed fit</span><strong>{{ $summary['confirmed'] }}</strong></article>
                <article class="summary-possible"><span>Possible—verify</span><strong>{{ $summary['possible'] }}</strong></article>
                <article class="summary-unverified"><span>Unverified</span><strong>{{ $summary['unverified'] }}</strong></article>
                <article class="summary-incompatible"><span>Not compatible</span><strong>{{ $summary['incompatible'] }}</strong></article>
            </section>

            <section class="panel results-panel">
                <div class="section-heading result-heading">
                    <div><span class="section-kicker">INVENTORY-WIDE RECOMMENDATIONS</span><h2>Compatible parts for this motorcycle</h2></div>
                    <div class="result-filters" aria-label="Filter results">
                        <button class="filter-button active" type="button" data-result-filter="all">All</button>
                        <button class="filter-button" type="button" data-result-filter="recommended">Recommended</button>
                        <button class="filter-button" type="button" data-result-filter="confirmed">Confirmed</button>
                        <button class="filter-button" type="button" data-result-filter="possible">Possible</button>
                        <button class="filter-button" type="button" data-result-filter="unverified">Unverified</button>
                        <button class="filter-button" type="button" data-result-filter="incompatible">Not compatible</button>
                    </div>
                </div>

                <div class="result-list">
                    @forelse($results as $result)
                        @php($assessment = $result['assessment'])
                        @php($product = $result['product'])
                        <article class="result-card status-{{ $assessment['code'] }}" data-result-status="{{ $assessment['code'] }}" data-recommended="{{ in_array($assessment['code'], ['confirmed', 'possible'], true) ? 'true' : 'false' }}">
                            <div class="result-card-header">
                                <div>
                                    <span class="result-category">{{ $product->category ?: 'Uncategorized' }} · {{ $product->sku }}</span>
                                    <h3>{{ $product->name }}</h3>
                                </div>
                                <span class="result-badge">{{ $assessment['label'] }}</span>
                            </div>
                            <div class="evidence-meter"><span style="width: {{ $assessment['confidence'] }}%"></span></div>
                            <div class="result-evidence">
                                <div>
                                    <h4>Why this result</h4>
                                    <ul>@foreach($assessment['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                </div>
                                @if($assessment['conditions'] !== [])
                                    <div>
                                        <h4>Conditions / checks required</h4>
                                        <ul>@foreach($assessment['conditions'] as $condition)<li>{{ $condition }}</li>@endforeach</ul>
                                    </div>
                                @endif
                            </div>
                            <footer>
                                <span>
                                    Evidence: {{ $assessment['source'] }}
                                    @if($assessment['reference']) · Reference: {{ $assessment['reference'] }} @endif
                                </span>
                                <span>₱{{ number_format((float) $product->unit_price, 2) }} · {{ $product->current_stock > 0 ? $product->current_stock.' in stock' : 'Out of stock' }}</span>
                            </footer>
                        </article>
                    @empty
                        <div class="empty-state">No active products matched your part search.</div>
                    @endforelse
                </div>
                <div class="empty-state" data-filter-empty hidden>No results are available in this status.</div>
            </section>
        @else
            <section class="panel checker-empty">
                <span class="checker-icon">AI</span>
                <h2>Enter motorcycle details to begin</h2>
                <p>Search by brand, model, year, engine, and variant. Leave the part field blank to check every active product.</p>
            </section>
        @endif

        <section class="safety-note">
            <strong>Safety rule:</strong> AI-assisted text interpretation can suggest what to verify, but it cannot create a confirmed fit. Safety-critical parts must use verified manufacturer or workshop fitment evidence.
        </section>

        @if($isAdmin)
            <section class="panel fitment-admin">
                <div class="section-heading">
                    <div><span class="section-kicker">ADMINISTRATION</span><h2>Structured Fitment Data</h2></div>
                    <span class="evidence-pill">Admin only</span>
                </div>
                <p class="admin-intro">Build trustworthy recommendations in this order: add a motorcycle profile, add product technical data, then save a sourced fitment decision.</p>

                <div class="admin-tool-grid">
                    <details class="admin-tool">
                        <summary>1. Add motorcycle profile</summary>
                        <form method="POST" action="{{ route('admin.compatibility.motorcycles.store') }}">
                            @csrf
                            <div class="compact-grid">
                                <label>Brand<input name="brand" required maxlength="100" placeholder="Honda"></label>
                                <label>Model<input name="model" required maxlength="100" placeholder="Click 160"></label>
                                <label>Year<input name="year" required type="number" min="1950" max="{{ now()->year + 2 }}" placeholder="2025"></label>
                                <label>Engine<input name="engine" required maxlength="100" placeholder="157cc"></label>
                            </div>
                            <label>Variant<input name="variant" maxlength="100" placeholder="ABS / Standard"></label>
                            <label>Technical specifications
                                <textarea name="motorcycle_specifications" placeholder="spark_plug_thread=M10&#10;brake_pad_shape=A12"></textarea>
                            </label>
                            <small>Enter one specification per line using key=value.</small>
                            <label>Features
                                <textarea name="motorcycle_features" placeholder="ABS&#10;fuel injection"></textarea>
                            </label>
                            <button class="admin-save" type="submit">Save motorcycle profile</button>
                        </form>
                    </details>

                    <details class="admin-tool">
                        <summary>2. Add product technical profile</summary>
                        <form method="POST" action="{{ route('admin.compatibility.products.profile') }}">
                            @csrf
                            <label>Product
                                <select name="product_id" required>
                                    <option value="">Select product</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->product_id }}">{{ $product->name }} · {{ $product->sku }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Description<textarea name="description" placeholder="Manufacturer description and supported applications"></textarea></label>
                            <label>Dimensions<input name="dimensions" maxlength="255" placeholder="Example: 94 × 38 × 7 mm"></label>
                            <label>Technical specifications
                                <textarea name="product_specifications" placeholder="spark_plug_thread=M10&#10;brake_pad_shape=A12"></textarea>
                            </label>
                            <small>Keys must match the motorcycle specification keys.</small>
                            <label>Required features<textarea name="required_features" placeholder="ABS&#10;fuel injection"></textarea></label>
                            <button class="admin-save" type="submit">Save product profile</button>
                        </form>
                    </details>

                    <details class="admin-tool">
                        <summary>3. Verify product fitment</summary>
                        <form method="POST" action="{{ route('admin.compatibility.fitments.store') }}">
                            @csrf
                            <label>Product
                                <select name="product_id" required>
                                    <option value="">Select product</option>
                                    @foreach($products as $product)
                                        <option value="{{ $product->product_id }}">{{ $product->name }} · {{ $product->sku }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Motorcycle
                                <select name="motorcycle_id" required>
                                    <option value="">Select motorcycle profile</option>
                                    @foreach($motorcycles as $motorcycle)
                                        <option value="{{ $motorcycle->motorcycle_id }}">{{ $motorcycle->display_name }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label>Decision
                                <select name="compatibility_status" required>
                                    <option value="exact_fit">Exact fit</option>
                                    <option value="conditional_fit">Conditional fit</option>
                                    <option value="incompatible">Incompatible</option>
                                    <option value="unverified">Unverified</option>
                                </select>
                            </label>
                            <label>Fitment notes<textarea name="fitment_notes" placeholder="Installation or fitment details"></textarea></label>
                            <label>Reasons<textarea name="reasons" placeholder="Manufacturer lists the exact model and year"></textarea></label>
                            <label>Conditions<textarea name="conditions" placeholder="Adapter, measurement, or installation requirements"></textarea></label>
                            <label>Reliable source reference<input name="source_reference" maxlength="255" placeholder="Catalog/manual name, edition, page, or URL"></label>
                            <small>A source is required for exact, conditional, and incompatible decisions.</small>
                            <button class="admin-save" type="submit">Save fitment decision</button>
                        </form>
                    </details>
                </div>
            </section>
        @endif
    </main>
</div>
</body>
</html>
