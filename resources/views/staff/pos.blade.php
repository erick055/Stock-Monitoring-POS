@php
$navigation = [
    ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','#'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','/staff/compatibility'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff POS Checkout | MotoSync</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/dashboard.css','resources/css/pos.css','resources/js/dashboard.js','resources/js/pos.js'])
</head>
<body>
<div class="dashboard-shell pos-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Staff navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 3 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Staff</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main pos-main">
        <header class="topbar pos-topbar">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div><p class="welcome">POS WORKSPACE</p><h1>POS Checkout</h1><p>Process orders, manage the cart, and complete payments.</p></div>
        </header>

        <section class="pos-layout" data-pos-app data-products='@json($products)' data-checkout-url="{{ route('staff.pos.checkout') }}">
            <div class="pos-catalog panel">
                <div class="pos-catalog-head">
                    <div>
                        <span class="section-kicker">POINT OF SALE</span>
                        <h2>MotoSync POS</h2>
                    </div>
                    <label class="pos-search">
                        <span>⌕</span>
                        <input type="search" placeholder="Search parts, SKUs, or services..." data-pos-search>
                    </label>
                </div>

                <div class="pos-categories">
                    <button class="cat-btn active" type="button" data-category="All">All Items</button>
                    @foreach($categories as $category)
                        <button class="cat-btn" type="button" data-category="{{ $category }}">{{ $category }}</button>
                    @endforeach
                </div>

                <div class="product-grid" data-product-grid></div>
            </div>

            <aside class="pos-cart panel">
                <div class="cart-header">
                    <div>
                        <span class="section-kicker">ACTIVE ORDER</span>
                        <h3>Current Order</h3>
                    </div>
                    <span class="cashier-badge">Cashier: {{ auth()->user()->name }}</span>
                </div>

                <div class="cart-items" data-cart-items>
                    <div class="empty-cart">Cart is empty.<br>Select items to begin.</div>
                </div>

                <div class="cart-summary">
                    <div class="summary-row"><span>Subtotal</span><span data-subtotal>P0.00</span></div>
                    <div class="summary-row"><span>Tax (12%)</span><span data-tax>P0.00</span></div>
                    <div class="summary-row total"><span>Total</span><span data-total>P0.00</span></div>
                </div>

                <div class="cart-actions">
                    <button class="btn btn-clear" type="button" data-clear-cart>Clear</button>
                    <button class="btn btn-hold" type="button" data-hold-order>Hold Order</button>
                    <button class="btn btn-pay" type="button" data-process-payment>Charge <span data-pay-total>P0.00</span></button>
                </div>
            </aside>
        </section>

        <div class="pos-toast" data-pos-toast hidden role="status">Order action saved in UI preview.</div>
    </main>
</div>
</body>
</html>
