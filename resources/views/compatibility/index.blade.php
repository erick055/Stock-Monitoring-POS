@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['⌂','Dashboard','/admin/dashboard'], ['▣','Stock Management','/admin/inventory'], ['□','Products','/admin/products'],
    ['⌁','Analytics','/admin/analytics'], ['!','Low Stock Alerts','/admin/low-stocks'], ['◎','Dead Stock','/admin/deadstock'],
    ['◇','Returns & Damages','/admin/returns'], ['♙','Supplier Price','/admin/suppliers'], ['⚙','Part Compatibility','#'],
] : [
    ['⌂','Dashboard','/staff/dashboard'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','#'],
];
$aiRoute = $isAdmin ? route('admin.compatibility.ai') : route('staff.compatibility.ai');
$statuses = [
    'compatible' => ['symbol' => '✓', 'label' => 'Compatible', 'description' => 'AI found support for this motorcycle and part combination.'],
    'possible' => ['symbol' => '~', 'label' => 'Possible', 'description' => 'A plausible fit, with an unresolved year, variant, or specification detail.'],
    'unknown' => ['symbol' => '?', 'label' => 'Not enough information', 'description' => 'Available information is missing or conflicting; AI cannot determine fit.'],
    'incompatible' => ['symbol' => '×', 'label' => 'Not compatible', 'description' => 'AI identified a specific fitment or specification mismatch.'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Motorcycle Parts Compatibility | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/compatibility.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/compatibility.js'])
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
                <p class="welcome">OPENAI-POWERED PART RESEARCH</p>
                <h1>AI Motorcycle Parts Compatibility</h1>
                <p>Find parts that fit, understand mismatches, and see the information behind each AI assessment.</p>
            </div>
        </header>

        @if($errors->any())
            <div class="compatibility-message error" role="alert">
                <strong>Please correct the form:</strong>
                <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <section class="panel search-panel">
            <div class="section-heading">
                <div><span class="section-kicker">AI COMPATIBILITY SEARCH</span><h2>Find compatible parts</h2></div>
                <span class="evidence-pill">Powered by OpenAI</span>
            </div>
            <form class="checker-form" method="POST" action="{{ $aiRoute }}">
                @csrf
                <div class="vehicle-search-grid">
                    <label>Brand<input name="brand" value="{{ $vehicleInput['brand'] }}" required placeholder="Honda"></label>
                    <label>Model<input name="model" value="{{ $vehicleInput['model'] }}" required placeholder="Click 160"></label>
                    <label>Year<input name="year" type="number" min="1950" max="{{ now()->year + 2 }}" value="{{ $vehicleInput['year'] }}" required placeholder="2025"></label>
                </div>
                <div class="checker-actions">
                    <label>Part name, SKU, brand, or category (optional)<input name="part_search" type="search" value="{{ request('part_search') }}" placeholder="e.g. brake pad, spark plug, or OEM number"></label>
                    <button class="search-action" type="submit">Ask AI for Recommendations</button>
                </div>
            </form>
            <p class="form-hint">Add a part name or number for more focused results. Relevant motorcycle brand/model inventory is prioritized, and AI assesses up to {{ min(10, max(1, (int) config('openai.max_products', 10)), min(5, max(1, (int) config('openai.max_recommendations', 5)))) }} products per search.</p>
            <p class="search-progress" role="status" data-search-progress hidden>AI is comparing motorcycle fitment and preparing your results. This may take a moment.</p>
        </section>

        <section class="panel fitment-legend" aria-label="Compatibility status legend">
            <div class="section-heading"><div><span class="section-kicker">READ YOUR RESULTS</span><h2>Compatibility legend</h2></div><span class="legend-note">AI assessments</span></div>
            <div class="legend-grid">
                @foreach($statuses as $status => $info)
                    <div class="legend-item status-{{ $status }}"><span class="status-symbol" aria-hidden="true">{{ $info['symbol'] }}</span><div><strong>{{ $info['label'] }}</strong><p>{{ $info['description'] }}</p></div></div>
                @endforeach
            </div>
        </section>

        @if($aiAdvice)
            <section class="vehicle-strip" aria-label="Searched motorcycle">
                <article><span>Brand</span><strong>{{ $vehicleInput['brand'] }}</strong></article>
                <article><span>Model</span><strong>{{ $vehicleInput['model'] }}</strong></article>
                <article><span>Year</span><strong>{{ $vehicleInput['year'] }}</strong></article>
            </section>

            <div class="catalog-message {{ $aiAdvice['available'] ? 'matched' : 'unmatched' }}">
                @if($aiAdvice['available'])
                    <strong>AI assessment:</strong> {{ $aiAdvice['summary'] }}
                    @if($candidateCount > 0)
                        <p class="research-meta">{{ ($aiAdvice['web_searched'] ?? false) ? 'Web search + AI assessment' : 'AI knowledge assessment · No live web sources' }} · {{ $results->count() }} results from {{ $candidateCount }} assessed candidates / {{ $matchingCount }} matching inventory products.</p>
                        @if($matchingCount > $candidateCount)<p class="research-meta">Showing a limited inventory selection. Add a specific part name or number to narrow your search.</p>@endif
                    @endif
                @else
                    {{ $aiAdvice['message'] }}
                @endif
            </div>

            @if($aiAdvice['available'])
                <section class="result-summary" aria-label="AI result summary">
                    <article class="summary-confirmed"><span>Compatible</span><strong>{{ $summary['compatible'] }}</strong></article>
                    <article class="summary-possible"><span>Possible</span><strong>{{ $summary['possible'] }}</strong></article>
                    <article class="summary-unverified"><span>Not enough information</span><strong>{{ $summary['unknown'] }}</strong></article>
                    <article class="summary-incompatible"><span>Not compatible</span><strong>{{ $summary['incompatible'] }}</strong></article>
                </section>

                <section class="panel results-panel">
                    <div class="section-heading result-heading">
                        <div><span class="section-kicker">AI-ASSESSED INVENTORY</span><h2>Recommendations for this motorcycle</h2></div>
                        <div class="result-filters" aria-label="Filter results">
                            <button class="filter-button active" type="button" data-result-filter="all" aria-pressed="true">All</button>
                            <button class="filter-button" type="button" data-result-filter="recommended">Recommended</button>
                            <button class="filter-button" type="button" data-result-filter="compatible">Compatible</button>
                            <button class="filter-button" type="button" data-result-filter="possible">Possible</button>
                            <button class="filter-button" type="button" data-result-filter="unknown">Not enough information</button>
                            <button class="filter-button" type="button" data-result-filter="incompatible">Not compatible</button>
                        </div>
                    </div>

                    <div class="result-list">
                        @forelse($results as $result)
                            @php($assessment = $result['assessment'])
                            @php($product = $result['product'])
                            <article class="result-card status-{{ $assessment['status'] }}" data-result-status="{{ $assessment['status'] }}" data-recommended="{{ in_array($assessment['status'], ['compatible', 'possible'], true) ? 'true' : 'false' }}">
                                <div class="result-card-header">
                                    <div>
                                        <span class="result-category">{{ $product->category ?: 'Uncategorized' }} · {{ $product->sku }}</span>
                                        <h3>{{ $product->name }}</h3>
                                    </div>
                                    <span class="result-badge"><span aria-hidden="true">{{ $statuses[$assessment['status']]['symbol'] }}</span> {{ $statuses[$assessment['status']]['label'] }}</span>
                                </div>
                                <p class="part-identity">{{ $product->manufacturer ?: 'Manufacturer not listed' }} · Part no. {{ $product->manufacturer_part_number ?: 'Not listed' }}</p>
                                <div class="result-evidence">
                                    <div><h4>Why AI gave this result</h4><p>{{ $assessment['reason'] }}</p></div>
                                    @if($assessment['details'] !== [])
                                        <div><h4>Fitment details</h4><ul>@foreach($assessment['details'] as $detail)<li>{{ $detail }}</li>@endforeach</ul></div>
                                    @endif
                                </div>
                                @if($assessment['sources'] !== [])
                                    <div class="ai-product-advice">
                                        <h4>Evidence references</h4>
                                        <ul>
                                            @foreach($assessment['sources'] as $source)
                                                <li>
                                                    @if(filter_var($source, FILTER_VALIDATE_URL) && in_array(parse_url($source, PHP_URL_SCHEME), ['http', 'https'], true))
                                                        <a href="{{ $source }}" target="_blank" rel="noopener noreferrer" title="{{ $source }}">{{ parse_url($source, PHP_URL_HOST) }} ↗</a>
                                                    @else
                                                        {{ $source }}
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @else
                                    <p class="source-note">No retrieved web citation for this part.</p>
                                @endif
                                <footer>
                                    <span>AI fitment assessment</span>
                                    <span>{{ $product->current_stock > 0 ? $product->current_stock.' in stock' : 'Out of stock' }} · ₱{{ number_format((float) $product->unit_price, 2) }}</span>
                                </footer>
                            </article>
                        @empty
                            <div class="empty-state">AI did not return a recommendation for the matching inventory.</div>
                        @endforelse
                    </div>
                    <div class="empty-state" data-filter-empty role="status" hidden>No results are available in this status. Choose another filter.</div>
                </section>
            @endif
        @else
            <section class="panel checker-empty">
                <span class="checker-icon">AI</span>
                <h2>Enter a motorcycle to begin</h2>
                <p>Enter the brand, model, and year above. Add a part number to get the most focused assessment.</p>
            </section>
        @endif

    </main>
</div>
</body>
</html>
