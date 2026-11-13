<section class="panel sales-target-card" aria-label="Monthly sales target">
    <div class="section-heading">
        <div><span class="section-kicker">SALES TARGET · {{ $salesTarget['month']->format('F Y') }}</span><h2>Monthly Sales Target</h2></div>
        <a class="sales-target-button" data-analytics-open="targets" href="{{ route('admin.analytics', ['tab' => 'targets']) }}"><i class="bi bi-bullseye" aria-hidden="true"></i> Manage target</a>
    </div>
    @if($salesTarget['amount'] !== null)
        <div class="sales-target-progress-heading"><div><strong>{{ number_format($salesTarget['percent'], 1) }}%</strong> <span>completed</span></div><span class="sales-target-status">{{ $salesTarget['status'] }}</span></div>
        <div class="sales-target-track" role="progressbar" aria-label="Stock cost recovery" aria-valuenow="{{ $salesTarget['bar_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div class="sales-target-recovery-fill" style="width: {{ $salesTarget['bar_percent'] }}%"></div></div>
        <dl class="sales-target-metrics">
            <div><dt>Stock purchase target</dt><dd>{{ \App\Support\Display::money($salesTarget['amount']) }}</dd></div>
            <div class="sales-target-recovered"><dt>Stock Cost Recovery</dt><dd>{{ \App\Support\Display::money($salesTarget['recovered']) }}</dd></div>
            <div><dt>Remaining</dt><dd>{{ \App\Support\Display::money($salesTarget['remaining']) }}</dd></div>
            <div><dt>Gross Sales</dt><dd>{{ \App\Support\Display::money($salesTarget['gross_sales']) }}</dd></div>
            <div @class(['sales-target-refunds' => $salesTarget['refunds'] > 0])><dt>Refunds Issued</dt><dd>{{ \App\Support\Display::money(-$salesTarget['refunds']) }}</dd></div>
            <div><dt>Net Sales</dt><dd>{{ \App\Support\Display::money($salesTarget['achieved']) }}</dd></div>
            <div><dt>Gross Sales Needed After Refunds</dt><dd>{{ \App\Support\Display::money($salesTarget['gross_sales_needed']) }}</dd></div>
            <div @class(['sales-target-surplus' => $salesTarget['above_target'] > 0])><dt>Above Target</dt><dd>{{ \App\Support\Display::money($salesTarget['above_target']) }}</dd></div>
            <div><dt>Gross Profit</dt><dd>{{ $salesTarget['gross_profit'] === null ? 'Unavailable' : \App\Support\Display::money($salesTarget['gross_profit']) }}</dd></div>
        </dl>
        <p class="sales-target-note">Approved refunds reduce net sales. Gross profit uses recorded sale costs, credits sellable returns back to inventory, and excludes operating expenses.{{ $salesTarget['gross_profit'] === null ? ' Some receipts are missing product lines.' : '' }}</p>
    @else
        <div class="sales-target-empty">
            <span class="sales-target-empty-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
            <div><strong>No target set for this month</strong><p>Enter this month’s stock purchase cost to track how much you’ve recovered.</p></div>
        </div>
    @endif
</section>
