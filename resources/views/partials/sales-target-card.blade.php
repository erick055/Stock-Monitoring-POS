<section class="panel sales-target-card" aria-label="Monthly sales target">
    <div class="section-heading">
        <div><span class="section-kicker">SALES TARGET · {{ $salesTarget['month']->format('F Y') }}</span><h2>Monthly Sales Target</h2></div>
        <a class="sales-target-button" data-analytics-open="targets" href="{{ route('admin.analytics', ['tab' => 'targets']) }}"><i class="bi bi-bullseye" aria-hidden="true"></i> Manage target</a>
    </div>
    @if($salesTarget['amount'] !== null)
        <div class="sales-target-progress-heading"><div><strong>{{ number_format($salesTarget['percent'], 1) }}%</strong> <span>completed</span></div><span class="sales-target-status">{{ $salesTarget['status'] }}</span></div>
        <div class="sales-target-track" role="progressbar" aria-label="Monthly sales target progress" aria-valuenow="{{ $salesTarget['bar_percent'] }}" aria-valuemin="0" aria-valuemax="100"><div style="width: {{ $salesTarget['bar_percent'] }}%"></div></div>
        <dl class="sales-target-metrics">
            <div><dt>Achieved · net sales</dt><dd>{{ \App\Support\Display::money($salesTarget['achieved']) }}</dd></div>
            <div><dt>Monthly target</dt><dd>{{ \App\Support\Display::money($salesTarget['amount']) }}</dd></div>
            <div><dt>Remaining</dt><dd>{{ \App\Support\Display::money($salesTarget['remaining']) }}</dd></div>
        </dl>
    @else
        <div class="sales-target-empty">
            <span class="sales-target-empty-icon" aria-hidden="true"><i class="bi bi-bullseye"></i></span>
            <div><strong>No target set for this month</strong><p>Set a monthly target to track your store’s progress.</p></div>
        </div>
    @endif
    <p class="sales-target-note">Based on paid sales after approved refunds. This measures sales, not profit.</p>
</section>
