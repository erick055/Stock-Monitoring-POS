<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }} | MotoSync</title>
    @vite(['resources/css/receipt.css'])
    @include('partials.appearance')
</head>
<body>
<div class="receipt-actions">
    <a href="{{ route(auth()->user()->role.'.pos') }}">Back to POS</a>
    <button class="primary" type="button" onclick="window.print()">Print receipt</button>
</div>

<main class="receipt-paper">
    <header class="receipt-brand">
        <h1>MOTOSYNC</h1>
        <p>Pareng RJJ Motorcycle Parts</p>
        <p>Official POS Receipt</p>
    </header>

    <div class="receipt-number"># POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }}</div>

    <section class="receipt-meta">
        <div><span>Date</span><strong>{{ $sale->sale_date->format('M d, Y h:i A') }}</strong></div>
        <div><span>Cashier</span><strong>{{ $sale->staff?->name ?? 'Former staff' }}</strong></div>
        <div><span>Payment</span><strong>{{ $sale->payment_label }}</strong></div>
        <div><span>Status</span><strong>{{ strtoupper($sale->payment_status) }}</strong></div>
    </section>

    <table class="receipt-items">
        <thead>
        <tr><th>Qty</th><th>Item</th><th>Amount</th></tr>
        </thead>
        <tbody>
        @if($sale->items->isEmpty())
            <tr>
                <td>1</td>
                <td><span class="receipt-item-name">Labor / service only</span><span class="receipt-item-sku">No products purchased</span></td>
                <td>{{ \App\Support\Display::money($sale->labor_amount) }}</td>
            </tr>
        @endif
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->quantity }}</td>
                <td>
                    <span class="receipt-item-name">{{ $item->product->name }}</span>
                    <span class="receipt-item-sku">{{ $item->product->sku }} @ {{ \App\Support\Display::money($item->unit_sale_price) }}</span>
                </td>
                <td>{{ \App\Support\Display::money($item->line_total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <section class="receipt-totals">
        <div class="receipt-total-row"><span>Subtotal</span><span>{{ \App\Support\Display::money($sale->subtotal) }}</span></div>
        @if((float) $sale->labor_amount > 0)
            <div class="receipt-total-row"><span>Labor</span><span>{{ \App\Support\Display::money($sale->labor_amount) }}</span></div>
        @endif
        @if($sale->amount_tendered !== null)
            <div class="receipt-total-row"><span>Cash tendered</span><span>{{ \App\Support\Display::money($sale->amount_tendered) }}</span></div>
            <div class="receipt-total-row"><span>Change due</span><span>{{ \App\Support\Display::money($sale->change_due) }}</span></div>
        @endif
        @if($sale->payment_reference)
            <div class="receipt-total-row"><span>Payment reference</span><span>{{ $sale->payment_reference }}</span></div>
        @endif
        <div class="receipt-total-row receipt-grand-total"><span>Total paid</span><span>{{ \App\Support\Display::money($sale->total_sale_amount) }}</span></div>
    </section>

    <footer class="receipt-footer">
        Thank you for choosing MotoSync.<br>
        Keep this receipt number for returns and documentation.
    </footer>
</main>
</body>
</html>
