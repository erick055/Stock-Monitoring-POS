<article class="panel analytics-panel" data-sales-chart>
                <div class="section-heading">
                    <div><span class="section-kicker">{{ strtoupper($chartPeriodLabel) }} VIEW · {{ strtoupper($chartRangeLabel) }}</span><h2>{{ $salesPeriod === 'day' ? 'Sales for a Specific Date' : ($salesPeriod === 'year' ? 'Sales Month by Month' : 'Sales Day by Day') }}</h2></div>
                    <div class="chart-period-menu" data-chart-period-menu>
                        <button class="chart-period-toggle" type="button" data-chart-period-toggle aria-label="Choose sales chart period" aria-haspopup="menu" aria-expanded="false"><span>Change period</span><i class="bi bi-three-dots" aria-hidden="true"></i></button>
                        <div class="chart-period-options" data-chart-period-options role="menu" hidden>
                            <a href="{{ route('admin.analytics', ['tab' => $chartTab, 'period' => 'day', 'range' => $selectedTransactionDate->toDateString()]) }}#transaction-history" role="menuitem" class="{{ $salesPeriod === 'day' ? 'is-active' : '' }}" @if($salesPeriod === 'day') aria-current="page" @endif><strong>Specific date</strong><small>Choose one day from the calendar</small></a>
                            <a href="{{ route('admin.analytics', ['tab' => $chartTab, 'period' => 'week', 'range' => $periodRanges['week'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'week' ? 'is-active' : '' }}" @if($salesPeriod === 'week') aria-current="page" @endif><strong>Weekly view</strong><small>Choose a week · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['tab' => $chartTab, 'period' => 'month', 'range' => $periodRanges['month'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'month' ? 'is-active' : '' }}" @if($salesPeriod === 'month') aria-current="page" @endif><strong>Monthly view</strong><small>Choose a month · day by day</small></a>
                            <a href="{{ route('admin.analytics', ['tab' => $chartTab, 'period' => 'year', 'range' => $periodRanges['year'], 'transaction_date' => $selectedTransactionDate->toDateString()]) }}" role="menuitem" class="{{ $salesPeriod === 'year' ? 'is-active' : '' }}" @if($salesPeriod === 'year') aria-current="page" @endif><strong>Yearly view</strong><small>Choose a year · month by month</small></a>
                        </div>
                    </div>
                </div>
                @if($salesPeriod === 'day')
                    <p class="sales-target-note">Specific date selected: {{ $selectedTransactionDate->format('F j, Y') }}. <a href="#transaction-history">View daily transactions and refunds</a>.</p>
                @else
                <form class="chart-range-filter" method="GET" action="{{ route('admin.analytics') }}">
                    <input type="hidden" name="tab" value="{{ $chartTab }}">
                    <input type="hidden" name="transaction_date" value="{{ $selectedTransactionDate->toDateString() }}">
                    <input type="hidden" name="period" value="{{ $salesPeriod }}">
                    <label for="chart-range-{{ $chartTab }}">Specific {{ $salesPeriod }}
                        <select id="chart-range-{{ $chartTab }}" name="range" data-chart-range>
                            @foreach($chartRangeOptions as $option)
                                <option value="{{ $option['value'] }}" @selected($option['value'] === $periodRanges[$salesPeriod])>{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit">View transactions</button>
                </form>
                <div class="chart-insight" data-chart-insight aria-live="polite">
                    <span>Select a day</span><strong>View its net sales total</strong>
                </div>
                <div class="day-chart-scroll" tabindex="0" role="region" aria-label="{{ $chartPeriodLabel }} sales chart; scroll horizontally to view all dates">
                <div class="day-chart period-{{ $salesPeriod }}" data-day-chart role="group" aria-label="Interactive {{ strtolower($chartPeriodLabel) }} bar chart of paid sales less approved refunds" style="--chart-columns: {{ $weeklySales->count() }}">
                    @foreach($weeklySales as $index => $day)
                        <button
                            class="day-column {{ $day['total'] < 0 ? 'sales-negative' : '' }}"
                            type="button"
                            data-day-bar
                            data-day="{{ $day['label'] }} · {{ $day['date'] }}"
                            data-total="{{ $day['total'] }}" data-gross="{{ $day['gross_sales'] }}" data-refunds="{{ $day['refunds'] }}"
                            style="--bar-height: {{ $day['total'] != 0 ? max(8, $day['percent']) : 2 }}%; --bar-index: {{ $index }}"
                            aria-label="{{ $day['label'] }}, {{ $day['date'] }}: {{ \App\Support\Display::money($day['total']) }} in net sales"
                        >
                            <strong class="day-value">{{ \App\Support\Display::money($day['total']) }}</strong>
                            <span class="day-bar-track"><i></i></span>
                            <span class="day-label">{{ $day['label'] }}</span>
                            <small>{{ $day['date'] }}</small>
                        </button>
                    @endforeach
                </div>
                </div>
                @endif
            </article>
