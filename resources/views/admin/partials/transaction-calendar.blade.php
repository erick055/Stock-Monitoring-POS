<div class="specific-date-view" id="transaction-history" aria-labelledby="transaction-history-title">
    <div class="section-heading"><div><span class="section-kicker">DAILY SALES</span><h3 id="transaction-history-title">Choose a specific date</h3><p>Select a date to see its paid transactions. Dates use {{ config('app.timezone') }}.</p></div></div>
    <form class="specific-date-picker" method="GET" action="{{ route('admin.analytics') }}#transaction-history">
        <input type="hidden" name="period" value="day">
        <label for="transaction-date-picker">Transaction date<input id="transaction-date-picker" type="date" name="range" value="{{ $selectedTransactionDate->toDateString() }}" required></label>
        <button class="apply-button" type="submit">View transactions</button>
    </form>
    <section class="daily-transactions daily-records-section" aria-labelledby="daily-transactions-title">
            <h3 id="daily-transactions-title">Transactions for {{ $selectedTransactionDate->format('F j, Y') }}</h3>
            <div class="daily-transaction-summary"><span><strong>{{ number_format($dayTransactions->total()) }}</strong> paid receipts</span><span><strong>{{ \App\Support\Display::money($dailySalesTotal) }}</strong> net sales</span><span>Gross sales <strong>{{ \App\Support\Display::money($dailySummary['gross_sales']) }}</strong></span><span>Refunds <strong>{{ \App\Support\Display::money($dailySummary['refunds']) }}</strong></span><span>Gross profit <strong>{{ \App\Support\Display::money($dailySummary['gross_profit']) }}</strong></span></div>
            <div class="table-wrap"><table class="daily-transactions-table"><thead><tr><th>Receipt</th><th>Time</th><th>Cashier</th><th>Units</th><th>Payment</th><th>Total</th><th>Action</th></tr></thead><tbody>
                @forelse($dayTransactions as $sale)
                    <tr><td>POS-{{ str_pad($sale->sale_id, 6, '0', STR_PAD_LEFT) }}</td><td>{{ $sale->sale_date->format('h:i A') }}</td><td>{{ $sale->staff?->name ?? 'Former staff' }}</td><td>{{ number_format($sale->units_count ?? 0) }}</td><td>{{ ucfirst($sale->payment_method ?? 'cash') }}</td><td>{{ \App\Support\Display::money((float) $sale->total_sale_amount) }}</td><td><a class="calendar-receipt-link" href="{{ route('admin.pos.receipts.show', $sale) }}" target="_blank" rel="noopener">View receipt</a></td></tr>
                @empty
                    <tr><td colspan="7" class="empty-analytics">No paid transactions on {{ $selectedTransactionDate->format('F j, Y') }}.</td></tr>
                @endforelse
            </tbody></table></div>
            @include('partials.ml-table-pagination', ['rows' => $dayTransactions, 'sizeName' => 'transactions_rows', 'pageName' => 'transactions_page', 'anchor' => 'transaction-history', 'tableLabel' => 'Daily transactions', 'hideWhenEmpty' => true])
    </section>
    <section class="daily-refunds daily-records-section" id="daily-refunds" aria-labelledby="daily-refunds-title">
            <h3 id="daily-refunds-title">Approved refunds for {{ $selectedTransactionDate->format('F j, Y') }}</h3>
            <div class="table-wrap"><table class="daily-transactions-table daily-refunds-table"><thead><tr><th>Return</th><th>Receipt</th><th>Approved at</th><th>Product</th><th>Quantity</th><th>Refund</th></tr></thead><tbody>
                @forelse($refundRows as $return)
                    <tr><td>#{{ $return->return_id }}</td><td>POS-{{ str_pad($return->sale_id, 6, '0', STR_PAD_LEFT) }}</td><td>{{ $return->approved_at->format('h:i A') }}</td><td>{{ $return->product?->name ?? 'Deleted product' }}</td><td>{{ $return->quantity }}</td><td>{{ \App\Support\Display::money($return->refund_amount) }}</td></tr>
                @empty
                    <tr><td colspan="6" class="empty-analytics">No approved refunds on this date.</td></tr>
                @endforelse
            </tbody></table></div>
            @include('partials.ml-table-pagination', ['rows' => $refundRows, 'sizeName' => 'refund_rows', 'pageName' => 'refund_page', 'anchor' => 'daily-refunds', 'tableLabel' => 'Approved refunds', 'hideWhenEmpty' => true])
    </section>
</div>
