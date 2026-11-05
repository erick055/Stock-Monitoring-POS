<tr data-receipt-id="{{ $sale->sale_id }}">
    <td><strong>POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }}</strong></td>
    <td>{{ $sale->sale_date->format('M d, Y h:i A') }}</td>
    <td>{{ $sale->staff?->name ?? 'Former staff' }}</td>
    @php($units = (int) ($sale->units_count ?? $sale->items->sum('quantity')))
    <td>{{ $units > 0 ? \App\Support\Display::count($units, 'unit') : 'Labor only' }}</td>
    <td><span class="payment-pill">{{ $sale->payment_label }}</span></td>
    <td><strong>{{ \App\Support\Display::money($sale->total_sale_amount) }}</strong></td>
    <td><a class="receipt-link" href="{{ route($posRoutePrefix.'.receipts.show', $sale) }}" target="_blank" rel="noopener">View receipt</a></td>
</tr>
