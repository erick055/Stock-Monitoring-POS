<section class="panel sales-target-card" aria-label="Monthly sales target">
    <div class="section-heading">
        <div><span class="section-kicker">SALES TARGET · {{ $salesTarget['month']->format('F Y') }}</span><h2>Monthly Sales Target</h2></div>
        <a class="sales-target-button" data-analytics-open="targets" href="{{ route('admin.analytics', ['tab' => 'targets']) }}"><i class="bi bi-bullseye" aria-hidden="true"></i> Manage target</a>
    </div>
    @if($salesTarget['amount'] !== null)
        <div class="sales-target-progress-heading"><div><strong>{{ number_format($salesTarget['percent'], 1) }}%</strong> <span>completed</span></div><span class="sales-target-status">{{ $salesTarget['status'] }}</span></div>
        @php
            $chartMax = max(0.01, $salesTarget['amount'], $salesTarget['achieved'], $salesTarget['refunds']);
            $chartPercent = fn ($amount) => $amount / $chartMax * 100;
        @endphp
        <figure class="sales-target-chart" aria-label="Monthly sales and refunds">
            <div class="sales-target-chart-label">
                <strong>Net Sales</strong>
                <span>{{ \App\Support\Display::money($salesTarget['achieved']) }}</span>
            </div>
            <div class="sales-target-chart-bar">
                <div class="sales-target-track" aria-hidden="true">
                    <div class="sales-target-recovery-fill" style="width: {{ $chartPercent($salesTarget['recovered']) }}%"></div>
                    <div class="sales-target-surplus-fill" style="width: {{ $chartPercent($salesTarget['above_target']) }}%"></div>
                </div>
                <span class="sales-target-chart-marker" style="left: {{ $chartPercent($salesTarget['amount']) }}%" aria-hidden="true"></span>
            </div>
            <ul class="sales-target-chart-key">
                <li class="sales-target-recovered"><span aria-hidden="true"></span> Stock Cost Recovery <strong>{{ \App\Support\Display::money($salesTarget['recovered']) }}</strong></li>
                <li class="sales-target-surplus"><span aria-hidden="true"></span> Above Target <strong>{{ \App\Support\Display::money($salesTarget['above_target']) }}</strong></li>
                <li class="sales-target-chart-target"><span aria-hidden="true"></span> Stock Purchase Target <strong>{{ \App\Support\Display::money($salesTarget['amount']) }}</strong></li>
            </ul>
            <div class="sales-target-chart-label sales-target-refunds">
                <strong>Refunds Issued</strong>
                <span>{{ \App\Support\Display::money(-$salesTarget['refunds']) }}</span>
            </div>
            <div class="sales-target-track" aria-hidden="true">
                <div class="sales-target-refund-fill" style="width: {{ $chartPercent($salesTarget['refunds']) }}%"></div>
            </div>
            <figcaption>Refunds are already deducted from net sales. Both bars use the same peso scale.</figcaption>
        </figure>
        <dl class="sales-target-metrics">
            <div><dt>Remaining</dt><dd>{{ \App\Support\Display::money($salesTarget['remaining']) }}</dd></div>
            <div><dt>Gross Sales</dt><dd>{{ \App\Support\Display::money($salesTarget['gross_sales']) }}</dd></div>
            <div><dt>Gross Sales Needed After Refunds</dt><dd>{{ \App\Support\Display::money($salesTarget['gross_sales_needed']) }}</dd></div>
            <div><dt>Gross Profit</dt><dd>{{ $salesTarget['gross_profit'] === null ? 'Unavailable' : \App\Support\Display::money($salesTarget['gross_profit']) }}</dd></div>
        </dl>
        <p class="sales-target-note">Gross profit uses recorded sale costs, credits sellable returns back to inventory, and excludes operating expenses.{{ $salesTarget['gross_profit'] === null ? ' Some receipts are missing product lines.' : '' }}</p>
    @else
        <div class="sales-target-empty">
            <span class="sales-target-empty-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
            <div><strong>No target set for this month</strong><p>Enter this month’s stock purchase cost to track how much you’ve recovered.</p></div>
        </div>
    @endif
</section>
