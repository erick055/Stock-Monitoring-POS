<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }} | MotoSync</title>
    @vite(['resources/css/receipt.css'])
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
        <div><span>Payment</span><strong>{{ ucfirst($sale->payment_method) }}</strong></div>
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
                <td>P{{ number_format($sale->labor_amount, 2) }}</td>
            </tr>
        @endif
        @foreach($sale->items as $item)
            <tr>
                <td>{{ $item->quantity }}</td>
                <td>
                    <span class="receipt-item-name">{{ $item->product->name }}</span>
                    <span class="receipt-item-sku">{{ $item->product->sku }} @ P{{ number_format($item->unit_sale_price, 2) }}</span>
                </td>
                <td>P{{ number_format($item->line_total, 2) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <section class="receipt-totals">
        <div class="receipt-total-row"><span>Subtotal</span><span>P{{ number_format($sale->subtotal, 2) }}</span></div>
        @if((float) $sale->labor_amount > 0)
            <div class="receipt-total-row"><span>Labor</span><span>P{{ number_format($sale->labor_amount, 2) }}</span></div>
        @endif
        <div class="receipt-total-row receipt-grand-total"><span>Total paid</span><span>P{{ number_format($sale->total_sale_amount, 2) }}</span></div>
    </section>

    <footer class="receipt-footer">
        Thank you for choosing MotoSync.<br>
        Keep this receipt number for returns and documentation.
    </footer>
</main>
</body>
</html>
