@php
$isAdmin = auth()->user()->role === 'admin';
$navigation = $isAdmin ? [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','#'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'], ['people','Account Management','/admin/accounts'],
] : [
    ['house-door','Dashboard','/staff/dashboard'], ['box-seam','Products','/staff/products'], ['cart3','POS Checkout','#'],
    ['arrow-repeat','Return & Damage','/staff/returns'], ['gear','Part Compatibility','/staff/compatibility'],
];
$posRoutePrefix = $isAdmin ? 'admin.pos' : 'staff.pos';
$activeIndex = $isAdmin ? 3 : 2;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $isAdmin ? 'Owner' : 'Staff' }} POS Checkout | MotoSync</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/dashboard.css','resources/css/pos.css','resources/css/responsive.css','resources/js/dashboard.js','resources/js/pos.js'])
</head>
<body>
<div class="dashboard-shell pos-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="{{ $isAdmin ? 'Administrator' : 'Staff' }} navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === $activeIndex ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>{{ $isAdmin ? 'Administrator' : 'Staff' }}</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main pos-main">
        <header class="topbar pos-topbar">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div><p class="welcome">POS WORKSPACE</p><h1>POS Checkout</h1><p>Process orders, manage the cart, and complete payments.</p></div>
        </header>

        <section class="pos-layout" data-pos-app data-products='@json($products)' data-held-orders='@json($heldOrders)' data-inventory-version="{{ $inventoryVersion }}" data-live-inventory-url="{{ route('inventory.live') }}" data-checkout-url="{{ route($posRoutePrefix.'.checkout') }}" data-hold-url="{{ route($posRoutePrefix.'.holds.store') }}">
            <div class="pos-catalog panel">
                <div class="pos-catalog-head">
                    <div>
                        <span class="section-kicker">POINT OF SALE</span>
                        <h2>MotoSync POS</h2>
                    </div>
                    <label class="pos-search">
                        <span>⌕</span>
                        <input type="search" placeholder="Search parts, SKUs, categories, or shelves..." data-pos-search>
                    </label>
                </div>

                <div class="pos-categories">
                    <button class="cat-btn active" type="button" data-category="All">All Items</button>
                    @foreach($categories as $category)
                        <button class="cat-btn" type="button" data-category="{{ $category['key'] }}">{{ $category['label'] }}</button>
                    @endforeach
                </div>

                <div class="product-grid" data-product-grid aria-live="polite"></div>
            </div>

            <aside class="pos-cart panel">
                <div class="cart-header">
                    <div>
                        <span class="section-kicker">ACTIVE ORDER</span>
                        <h3>Current Order</h3>
                    </div>
                    <div class="cart-badges">
                        <span class="active-hold-badge" data-active-hold hidden></span>
                        <span class="cashier-badge">Cashier: {{ auth()->user()->name }}</span>
                    </div>
                </div>

                <div class="cart-items" data-cart-items>
                    <div class="empty-cart">No products selected.<br>Add a product or enter a labor charge.</div>
                </div>

                <label class="labor-field">
                    <span>Labor charge <small>Optional</small></span>
                    <div class="labor-input-wrap">
                        <span>P</span>
                        <input type="number" min="0" max="9999999999.99" step="0.01" inputmode="decimal" placeholder="0.00" data-labor-amount aria-label="Optional labor charge">
                    </div>
                    <small>Added to the merchandise subtotal and recorded on the receipt.</small>
                </label>

                <div class="cart-summary">
                    <div class="summary-row"><span>Subtotal</span><span data-subtotal>P0.00</span></div>
                    <div class="summary-row"><span>Labor</span><span data-labor-total>P0.00</span></div>
                    <div class="summary-row total"><span>Total</span><span data-total>P0.00</span></div>
                </div>

                <div class="cart-actions">
                    <button class="btn btn-clear" type="button" data-clear-cart>Clear</button>
                    <button class="btn btn-hold" type="button" data-hold-order>Hold Order</button>
                    <button class="btn btn-pay" type="button" data-process-payment>Charge <span data-pay-total>P0.00</span></button>
                </div>
            </aside>
        </section>

        <section class="held-orders panel" aria-labelledby="held-orders-title">
            <div class="checkout-log-head">
                <div>
                    <span class="section-kicker">PARKED CARTS</span>
                    <h2 id="held-orders-title">Held Orders</h2>
                    <p>Resume a saved cart when the customer is ready to continue.</p>
                </div>
                <span class="log-count" data-held-count>{{ $heldOrders->count() }} active</span>
            </div>
            <div class="held-order-grid" data-held-order-list>
                <div class="empty-holds" data-empty-holds {{ $heldOrders->isNotEmpty() ? 'hidden' : '' }}>No active held orders.</div>
            </div>
        </section>

        <section class="checkout-log panel" aria-labelledby="checkout-log-title">
            <div class="checkout-log-head">
                <div>
                    <span class="section-kicker">DOCUMENTATION</span>
                    <h2 id="checkout-log-title">Checkout Log</h2>
                    <p>Recent completed transactions and their permanent receipts.</p>
                </div>
                <span class="log-count">Latest 25 sales</span>
            </div>

            <div class="table-wrap checkout-log-table">
                <table>
                    <thead>
                    <tr>
                        <th>Receipt #</th>
                        <th>Date &amp; Time</th>
                        <th>Cashier</th>
                        <th>Items</th>
                        <th>Payment</th>
                        <th>Total</th>
                        <th>Document</th>
                    </tr>
                    </thead>
                    <tbody data-checkout-logs>
                    @forelse($checkoutLogs as $sale)
                        <tr>
                            <td><strong>POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }}</strong></td>
                            <td>{{ $sale->sale_date->format('M d, Y h:i A') }}</td>
                            <td>{{ $sale->staff?->name ?? 'Former staff' }}</td>
                            <td>{{ (int) $sale->units_count > 0 ? (int) $sale->units_count.' unit'.((int) $sale->units_count === 1 ? '' : 's') : 'Labor only' }}</td>
                            <td><span class="payment-pill">{{ ucfirst($sale->payment_method) }}</span></td>
                            <td><strong>P{{ number_format($sale->total_sale_amount, 2) }}</strong></td>
                            <td><a class="receipt-link" href="{{ route($posRoutePrefix.'.receipts.show', $sale) }}" target="_blank" rel="noopener">View receipt</a></td>
                        </tr>
                    @empty
                        <tr data-empty-log><td colspan="7" class="empty-log">No completed checkouts yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </section>

        <div class="pos-toast" data-pos-toast hidden role="status">Order action saved in UI preview.</div>
    </main>
</div>

<div class="receipt-modal" data-receipt-modal hidden role="dialog" aria-modal="true" aria-labelledby="receipt-preview-title">
    <div class="receipt-modal-card">
        <div class="receipt-modal-head">
            <div>
                <span class="section-kicker">PAYMENT COMPLETE</span>
                <h2 id="receipt-preview-title">Receipt <span data-receipt-number></span></h2>
            </div>
            <button class="receipt-close" type="button" data-close-receipt aria-label="Close receipt">&times;</button>
        </div>
        <div class="receipt-preview">
            <div class="receipt-preview-meta">
                <span data-receipt-date></span>
                <span data-receipt-cashier></span>
            </div>
            <div class="receipt-preview-items" data-receipt-items></div>
            <div class="receipt-preview-totals">
                <div><span>Subtotal</span><strong data-receipt-subtotal></strong></div>
                <div data-receipt-labor-row><span>Labor</span><strong data-receipt-labor></strong></div>
                <div class="receipt-preview-total"><span>Total paid</span><strong data-receipt-total></strong></div>
            </div>
        </div>
        <div class="receipt-modal-actions">
            <button class="btn btn-hold" type="button" data-close-receipt>Continue selling</button>
            <a class="btn btn-pay receipt-print-link" data-receipt-print href="#" target="_blank" rel="noopener">Open printable receipt</a>
        </div>
    </div>
</div>
</body>
</html>
