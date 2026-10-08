@php
    $loginCriticalStocks = collect();
    if (auth()->user()?->role === 'admin' && session()->pull('stock.login_alert_pending', false)) {
        $loginCriticalStocks = \App\Models\Product::query()
            ->where('is_active', true)
            ->whereColumn('current_stock', '<=', 'reorder_level')
            ->orderBy('current_stock')->orderBy('name')
            ->get(['sku', 'name', 'current_stock', 'reorder_level']);
    }
@endphp
@if($loginCriticalStocks->isNotEmpty())
    <dialog class="login-stock-alert" data-login-stock-alert aria-labelledby="login-stock-alert-title" aria-describedby="login-stock-alert-description">
        <header><span class="section-kicker">INVENTORY ALERT</span><h2 id="login-stock-alert-title">Critical low stock</h2></header>
        <p id="login-stock-alert-description">{{ $loginCriticalStocks->count() }} active {{ $loginCriticalStocks->count() === 1 ? 'product needs' : 'products need' }} restocking. Stock is at or below the reorder level.</p>
        <div class="login-stock-alert-table" tabindex="0" role="region" aria-label="Critical stock products">
            <table>
                <thead><tr><th>Product / SKU</th><th>Stock left</th><th>Reorder level</th></tr></thead>
                <tbody>
                    @foreach($loginCriticalStocks as $stock)
                        <tr><td><strong>{{ $stock->name }}</strong><small>{{ $stock->sku }}</small></td><td>{{ $stock->current_stock }}@if($stock->current_stock <= 0)<small class="login-stock-empty">Out of stock</small>@endif</td><td>{{ $stock->reorder_level }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <footer><form method="dialog"><button type="submit" autofocus>Dismiss</button></form><a href="{{ route('admin.low-stocks', ['status' => 'critical']) }}">View low stock alerts</a></footer>
    </dialog>
@endif
