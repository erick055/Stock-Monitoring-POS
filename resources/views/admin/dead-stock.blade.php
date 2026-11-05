@php
$navigation = [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock', '/admin/deadstock'],
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
    <title>Dead Stock | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/dead-stock.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/dead-stock.js'])
    @include('partials.appearance')
</head>
<body>
<div class="dashboard-shell dead-stock-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index=> $item)
                <a class="nav-link {{ $index === 6 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Administrator</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main dead-stock-main">
        <header class="dead-stock-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div>
                <p class="welcome">INVENTORY RISK &amp; MACHINE LEARNING</p>
                <h1>Dead Stock Detection</h1>
                <p>Automated scoring analyzes POS sales, stock aging, demand, and trapped capital.</p>
            </div>
        </header>

        @if(session('success'))<div class="promotion-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="promotion-message error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="stat-grid dead-stock-stats" aria-label="Dead stock summary">
            @foreach($summary as $card)
                <article class="stat-card {{ $card[3] }}"><div class="stat-head"><span>{{ $card[0] }}</span><span class="trend-dot"></span></div><strong>{{ $card[1] }}</strong><small>{{ $card[2] }}</small></article>
            @endforeach
        </section>

        <section class="panel demand-outlook-panel" id="demand-outlook" aria-labelledby="demand-insight-title">
            <div class="demand-outlook-header">
            <div class="demand-insight-icon" aria-hidden="true"><i class="bi bi-stars"></i></div>
            <div class="demand-insight-copy">
                <div class="demand-insight-heading">
                    <div>
                        <span class="section-kicker">DEMAND OUTLOOK</span>
                        <h2 id="demand-insight-title">Spot products that may stop selling</h2>
                    </div>
                    <span class="model-status {{ $latestMlModel ? 'ready' : 'waiting' }}">
                        <i></i>{{ $latestMlModel ? 'Predictions ready' : 'Waiting for sales history' }}
                    </span>
                </div>
                <p class="demand-insight-summary">
                    @if($latestMlModel)
                        Predictions update hourly in the background when the scheduler is running. Products with the highest risk appear first.
                    @else
                        Once there is enough POS history, this tool learns from what sold and what stayed on the shelf to estimate the next 90 days.
                    @endif
                </p>
                @if($latestMlModel)
                    <details class="model-details">
                        <summary>About this update</summary>
                        <p>
                            Updated {{ $latestMlModel->trained_at->diffForHumans() }} using {{ number_format($latestMlModel->training_samples) }} historical product snapshots
                            @if($latestMlModel->validation_accuracy !== null)
                                , with {{ number_format($latestMlModel->validation_accuracy * 100, 1) }}% chronological holdout accuracy (90-day training outcomes do not overlap the validation period)
                            @endif.
                        </p>
                    </details>
                @endif
            </div>
            </div>
            <div class="table-wrap outlook-table">
                <table>
                    <thead><tr><th>Product</th><th>Stock</th><th>90-Day Outlook</th><th>Chance of No Demand</th><th>Observations</th><th>Updated</th></tr></thead>
                    <tbody>
                        @forelse($outlookItems as $item)
                            <tr>
                                <td><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }}</small></td>
                                <td>{{ number_format($item['stock']) }}</td>
                                @if($item['ml_prediction'])
                                    <td><span class="ai-badge {{ $item['ml_prediction']['probability'] >= .7 ? 'dead-stock' : ($item['ml_prediction']['probability'] >= .4 ? 'slow-moving' : 'healthy') }}">{{ $item['ml_prediction']['classification'] }}</span></td>
                                    <td><strong>{{ number_format($item['ml_prediction']['probability'] * 100, 1) }}%</strong></td>
                                    <td>{{ implode(' · ', $item['ml_prediction']['factors']) ?: 'No notable warning signs in recent sales.' }}</td>
                                    <td>{{ $item['ml_prediction']['predicted_at']->diffForHumans() }}</td>
                                @else
                                    <td><span class="outlook-pending">Waiting for history</span></td>
                                    <td>—</td>
                                    <td>More sales history is needed to predict demand.</td>
                                    <td>—</td>
                                @endif
                            </tr>
                        @empty
                            <tr><td colspan="6" class="empty-table">No stocked products match your search.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @include('partials.ml-table-pagination', ['rows' => $outlookItems, 'sizeName' => 'outlook_rows', 'pageName' => 'outlook_page', 'anchor' => 'demand-outlook', 'tableLabel' => 'Demand outlook'])
        </section>

        <section class="panel detail-panel risk-inventory-panel" id="risk-inventory">
            <datalist id="bundle-product-options">
                @foreach($bundleProducts as $bundleProduct)
                    <option value="{{ $bundleProduct->sku }} — {{ $bundleProduct->name }}" data-product-id="{{ $bundleProduct->product_id }}">{{ $bundleProduct->current_stock }} in stock</option>
                @endforeach
            </datalist>
            <div class="section-heading">
                <div><span class="section-kicker">{{ $status === 'archived' ? 'ARCHIVE' : 'RISK RECOVERY QUEUE' }}</span><h2>{{ $status === 'archived' ? 'Archived Dead-Stock Items' : 'Inventory Unlikely to Sell Soon' }}</h2><small>{{ number_format($riskItems->total()) }} matching products. {{ $status === 'archived' ? 'Archived records remain in inventory and can be restored.' : 'ML predictions appear after training; only an administrator can approve an offer.' }}</small></div>
            </div>
            <nav class="archive-tabs" aria-label="Dead stock queue views">
                <a class="{{ $status === 'queue' ? 'active' : '' }}" href="{{ route('admin.dead-stock') }}">Active queue</a>
                <a class="{{ $status === 'archived' ? 'active' : '' }}" href="{{ route('admin.dead-stock', ['status' => 'archived']) }}">Archived <span>{{ $summary[3][1] }}</span></a>
            </nav>
            <form class="data-toolbar" method="GET" action="{{ route('admin.dead-stock') }}">
                <input type="hidden" name="status" value="{{ $status }}">
                <label class="compact-search"><span>Search</span><input name="search" value="{{ $search }}" placeholder="Product or SKU"></label>
                <label><span>Risk</span><select name="classification"><option value="all" @selected($classification === 'all')>All risks</option><option value="dead" @selected($classification === 'dead')>Dead stock</option><option value="slow" @selected($classification === 'slow')>Slow moving</option>@if($status === 'archived')<option value="healthy" @selected($classification === 'healthy')>Now healthy</option>@endif</select></label>
                <label><span>Rows</span><select name="per_page">@foreach([20,50,100] as $size)<option value="{{ $size }}" @selected($perPage === $size)>{{ $size }}</option>@endforeach</select></label>
                <button type="submit">Apply</button>
                @if($search || $classification !== 'all' || $perPage !== 20)<a href="{{ route('admin.dead-stock', $status === 'archived' ? ['status' => 'archived'] : []) }}">Reset</a>@endif
            </form>
            <div class="table-wrap risk-table">
                <table>
                    <thead><tr><th>Product</th><th>Risk</th><th>Score</th><th>Stock</th><th>30-Day Sales</th><th>Last Sale</th><th>Capital</th><th>Analysis</th><th>Owner Action</th></tr></thead>
                    <tbody>
                    @forelse($riskItems as $item)
                        <tr>
                            <td><strong>{{ $item['name'] }}</strong><small>{{ $item['sku'] }} | {{ $item['age'] }}</small></td>
                            <td><span class="ai-badge {{ $item['classification_class'] }}">{{ $item['classification'] }}</span></td>
                            <td><div class="table-score" title="Measured inventory risk score"><strong>{{ $item['score'] }}</strong><div class="score-track"><i class="{{ $item['classification_class'] }}" style="width:{{ $item['score_width'] }}"></i></div></div></td>
                            <td>{{ number_format($item['stock']) }}</td>
                            <td>{{ number_format($item['monthly_units']) }}</td>
                            <td>{{ $item['last_sale'] }}</td>
                            <td><strong>{{ $item['total_cost'] }}</strong></td>
                            <td>
                                <button class="deadstock-row-button" type="button" data-deadstock-open="analysis-{{ $item['product_id'] }}" aria-haspopup="dialog">View</button>
                                <dialog class="deadstock-dialog risk-details" id="analysis-{{ $item['product_id'] }}" aria-labelledby="analysis-title-{{ $item['product_id'] }}">
                                    <header class="deadstock-dialog-header"><div><span class="section-kicker">PRODUCT ANALYSIS</span><h2 id="analysis-title-{{ $item['product_id'] }}">{{ $item['name'] }}</h2></div><button type="button" data-deadstock-close aria-label="Close analysis"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
                                    <ul>@foreach($item['reasons'] as $reason)<li>{{ $reason }}</li>@endforeach</ul>
                                    @if($item['ml_prediction'])
                                        <div class="demand-result">
                                            <span>90-day demand outlook</span>
                                            <strong>{{ $item['ml_prediction']['classification'] }}</strong>
                                            <p>{{ number_format($item['ml_prediction']['probability'] * 100, 1) }}% chance of receiving no demand in the next 90 days</p>
                                        </div>
                                        @if($item['ml_prediction']['factors'])
                                            <p class="analysis-label">Why this was flagged</p>
                                            <ul>@foreach($item['ml_prediction']['factors'] as $factor)<li>{{ $factor }}</li>@endforeach</ul>
                                        @endif
                                    @else
                                        <p class="demand-waiting"><strong>Demand outlook not ready yet.</strong> The risk score above is based on current stock and recent sales.</p>
                                    @endif
                                    <p>{{ $item['recommendation'] }}</p>
                                    <footer class="deadstock-dialog-footer"><button class="deadstock-row-button" type="button" data-deadstock-close>Close</button></footer>
                                </dialog>
                            </td>
                            <td>
                                @if($status === 'archived')
                                    <div class="archive-record">
                                        <strong>Archived {{ $item['archived_at'] }}</strong>
                                        <small>By {{ $item['archived_by'] }}</small>
                                        @if($item['archive_note'])<p>{{ $item['archive_note'] }}</p>@endif
                                        <form method="POST" action="{{ route('admin.dead-stock.restore', $item['product_id']) }}" data-restore-dead-stock>
                                            @csrf @method('PATCH')
                                            <button type="submit">Restore to active queue</button>
                                        </form>
                                    </div>
                                @else
                                <div class="deadstock-owner-actions">
                                    <button class="deadstock-row-button" type="button" data-deadstock-open="promotion-{{ $item['product_id'] }}" aria-haspopup="dialog">Choose action</button>
                                    <button class="deadstock-row-button muted" type="button" data-deadstock-open="archive-{{ $item['product_id'] }}" aria-haspopup="dialog">Archive item</button>
                                </div>
                                <dialog class="deadstock-dialog promotion-details" id="promotion-{{ $item['product_id'] }}" aria-labelledby="promotion-title-{{ $item['product_id'] }}">
                                    <header class="deadstock-dialog-header"><div><span class="section-kicker">OWNER ACTION</span><h2 id="promotion-title-{{ $item['product_id'] }}">{{ $item['name'] }}</h2></div><button type="button" data-deadstock-close aria-label="Close owner action"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
                                    @if($item['active_promotion'])
                                        <div class="active-promotion">
                                            <strong>{{ $item['active_promotion']['label'] }}{{ $item['active_promotion']['action_type'] === 'promo_bundle' ? ' · Free in POS' : ' · '.number_format($item['active_promotion']['discount_percent'], 2).'% off' }}</strong>
                                            <span>{{ \App\Support\Display::money($item['unit_price']) }} → {{ \App\Support\Display::money($item['active_promotion']['promotional_price']) }}</span>
                                            @if($item['active_promotion']['bundle_product'])<small>Bundle with {{ $item['active_promotion']['bundle_product']['name'] }} ({{ $item['active_promotion']['bundle_product']['sku'] }})</small>@endif
                                            @if($item['active_promotion']['bundle_note'])<small>{{ $item['active_promotion']['bundle_note'] }}</small>@endif
                                            <small>Approved by {{ $item['active_promotion']['administrator'] }}</small>
                                            <form method="POST" action="{{ route('admin.dead-stock.promotions.end', $item['product_id']) }}" data-end-promotion>
                                                @csrf @method('DELETE')
                                                <button class="end-promotion" type="submit">End offer</button>
                                            </form>
                                        </div>
                                    @endif
                                    <form class="promotion-form" method="POST" action="{{ route('admin.dead-stock.promotions.apply', $item['product_id']) }}" data-promotion-form>
                                        @csrf
                                        <input type="hidden" name="action_type" value="discount" data-action-type>
                                        <div class="promotion-actions" role="group" aria-label="Choose promotion for {{ $item['name'] }}">
                                            <button class="selected" type="button" data-promotion-action="discount">Discount</button>
                                            <button type="button" data-promotion-action="promo_bundle">Promo bundle</button>
                                        </div>
                                        <label data-discount-field>Discount percentage
                                            <span class="discount-input"><input name="discount_percent" type="number" min="1" max="90" step="0.01" value="{{ $item['active_promotion']['discount_percent'] ?? 10 }}" required><b>%</b></span>
                                        </label>
                                        <label data-bundle-field hidden>Search bundle product
                                            <input type="search" list="bundle-product-options" autocomplete="off" placeholder="Type product name or SKU" data-bundle-search>
                                            <input name="bundle_product_id" type="hidden" data-bundle-product-id>
                                            <small>Select an exact inventory product from the suggestions.</small>
                                        </label>
                                        <p class="price-preview" data-price-preview data-base-price="{{ $item['unit_price'] }}">Regular {{ \App\Support\Display::money($item['unit_price']) }}</p>
                                        <button class="approve-promotion" type="submit">Approve selected action</button>
                                        <small>Nothing changes until you press approve.</small>
                                    </form>
                                    <footer class="deadstock-dialog-footer"><button class="deadstock-row-button" type="button" data-deadstock-close>Close</button></footer>
                                </dialog>
                                <dialog class="deadstock-dialog archive-details" id="archive-{{ $item['product_id'] }}" aria-labelledby="archive-title-{{ $item['product_id'] }}">
                                    <header class="deadstock-dialog-header"><div><span class="section-kicker">ARCHIVE ITEM</span><h2 id="archive-title-{{ $item['product_id'] }}">{{ $item['name'] }}</h2></div><button type="button" data-deadstock-close aria-label="Close archive window"><i class="bi bi-x-lg" aria-hidden="true"></i></button></header>
                                    <form method="POST" action="{{ route('admin.dead-stock.archive', $item['product_id']) }}" data-archive-dead-stock>
                                        @csrf @method('PATCH')
                                        <label>Archive note <small>Optional</small><textarea name="archive_note" rows="2" maxlength="500" placeholder="Why is this item being archived?"></textarea></label>
                                        <small>This only hides the item from this queue. Inventory and POS are unchanged.</small>
                                        <button type="submit">Archive from queue</button>
                                    </form>
                                    <footer class="deadstock-dialog-footer"><button class="deadstock-row-button" type="button" data-deadstock-close>Cancel</button></footer>
                                </dialog>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="empty-table">{{ $status === 'archived' ? 'No archived products match the selected filters.' : 'No products match the selected risk filters.' }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            @include('partials.ml-table-pagination', ['rows' => $riskItems, 'sizeName' => 'per_page', 'pageName' => 'page', 'anchor' => 'risk-inventory', 'tableLabel' => 'At-risk inventory'])
        </section>

        <section class="panel summary-panel ai-summary-panel" aria-labelledby="ai-summary-title">
            <div class="section-heading">
                <div><span class="section-kicker"><i class="bi bi-stars" aria-hidden="true"></i> AI RECOMMENDATIONS</span><h2 id="ai-summary-title">AI Recommendation Summary</h2></div>
                <button class="apply-button" type="button" data-refresh-summary>Refresh</button>
            </div>
            <p class="ai-summary-description">Recovery guidance based on recorded sales, stock levels, and inventory rules.</p>
            <div class="recommendation-list">
                @foreach($recommendations as $recommendation)
                    <p>{{ $recommendation }}</p>
                @endforeach
            </div>
        </section>

        <div class="dead-stock-toast" data-dead-stock-toast hidden role="status">Recommendation summary refreshed.</div>
    </main>
</div>
@include('partials.login-stock-alert')
</body>
</html>
