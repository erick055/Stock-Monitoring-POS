@include('partials.sales-target-card')
@if($errors->hasAny(['target_month', 'target_amount']))<p class="analytics-message error" data-target-validation-error role="alert">{{ $errors->first('target_month') ?: $errors->first('target_amount') }}</p>@endif
<section class="panel analytics-panel">
    <div class="section-heading"><div><span class="section-kicker">OWNER SETTINGS</span><h2>Set monthly sales target</h2></div><span class="period">Store-wide · net sales</span></div>
    <form class="sales-target-form" method="POST" action="{{ route('admin.analytics.targets.store') }}">
        @csrf
        <label for="target-month">Target month<input id="target-month" name="target_month" type="month" min="2000-01" max="2100-12" value="{{ old('target_month', now()->format('Y-m')) }}" required></label>
        <label for="target-amount">Target amount (₱)<input id="target-amount" name="target_amount" type="number" min="0.01" max="9999999999.99" step="0.01" value="{{ old('target_amount', $salesTarget['amount']) }}" required></label>
        <button class="sales-target-button" type="submit">Save target</button>
    </form>
    <p class="sales-target-note">Saving a month that already has a target updates its amount. Past targets are listed below.</p>
</section>
<section class="panel analytics-panel" id="sales-target-history">
    <div class="section-heading"><div><span class="section-kicker">TARGET HISTORY</span><h2>Monthly results</h2></div></div>
    <div class="table-wrap"><table class="velocity-table"><thead><tr><th>Month</th><th>Target</th><th>Net sales</th><th>Progress</th><th>Remaining</th><th>Status</th></tr></thead><tbody>
    @forelse($targetRows as $target)
        <tr><td>{{ $target['month']->format('F Y') }}</td><td>{{ \App\Support\Display::money($target['amount']) }}</td><td>{{ \App\Support\Display::money($target['achieved']) }}</td><td>{{ number_format($target['percent'], 1) }}%</td><td>{{ \App\Support\Display::money($target['remaining']) }}</td><td>{{ $target['status'] }}</td></tr>
    @empty
        <tr><td colspan="6" class="empty-analytics">No sales targets yet. Set your first monthly target above.</td></tr>
    @endforelse
    </tbody></table></div>
    @include('partials.ml-table-pagination', ['rows' => $targetRows, 'sizeName' => 'target_rows', 'pageName' => 'target_page', 'anchor' => 'sales-target-history', 'tableLabel' => 'Sales target history', 'hideWhenEmpty' => true])
</section>
