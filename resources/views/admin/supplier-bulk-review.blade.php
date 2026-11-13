<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review supplier products | MotoSync</title>
    @vite(['resources/css/dashboard.css', 'resources/css/suppliers.css', 'resources/css/responsive.css', 'resources/js/suppliers.js'])
    @include('partials.appearance')
</head>
<body>
<main class="dashboard-main supplier-review">
    @php
        $price = $prices->get($selected);
        $details = $entries[$selected];
        $readyCount = count(array_filter($ready));
    @endphp
    <header class="section-heading">
        <div><a href="{{ route('admin.suppliers') }}" target="_top">Back to Supplier Price</a><h1>Review supplier products</h1></div>
        <strong data-review-progress>{{ $readyCount }} of {{ count($entries) }} ready</strong>
    </header>
    <form class="supplier-review-grid" id="supplier-review-form" method="POST" action="{{ route('admin.suppliers.bulk.save', [$batch, $selected]) }}">
        @csrf
        <nav class="panel supplier-review-list" aria-label="Selected supplier products">
            @foreach($entries as $id => $entry)
                <button type="submit" name="destination" value="{{ $id }}" formnovalidate @if($id === $selected) aria-current="true" @endif @disabled(!$price)>
                    <strong>{{ $prices->get($id)?->product_name ?? 'Unavailable product' }}</strong>
                    <span>{{ $prices->get($id)?->supplier_sku ?? '#'.$id }} · {{ $ready[$id] ? 'Ready' : 'Needs details' }}</span>
                </button>
            @endforeach
        </nav>
        <section class="panel supplier-review-details" tabindex="-1">
            @if($errors->any())
                <ul role="alert">
                    @foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach
                </ul>
            @endif
            @if($price)
                <h2>{{ $price->product_name }}</h2>
                <p>Supplier SKU {{ $price->supplier_sku }} · Supplier availability {{ $price->available_quantity ?? 'Unknown' }}</p>
                <p>Imported shop opening stock {{ $price->shop_received_quantity ?? 'Not supplied' }}</p>
                <div class="supplier-review-fields">
                    <label>Category<input name="category" list="review-categories" maxlength="100" value="{{ $details['category'] ?? '' }}"></label>
                    <label>Shelf location<input name="shelf_location" list="review-shelves" maxlength="100" value="{{ $details['shelf_location'] ?? '' }}"></label>
                    <label>Manufacturer part number<input name="manufacturer_part_number" maxlength="150" value="{{ $details['manufacturer_part_number'] ?? '' }}" required></label>
                    <label>Opening stock<input type="number" name="initial_stock" min="0" max="2147483647" step="1" value="{{ $details['initial_stock'] ?? '' }}" required><small>Confirm the units your shop has on hand.</small></label>
                    <label>Reorder level<input type="number" name="reorder_level" min="0" max="2147483647" step="1" value="{{ $details['reorder_level'] ?? '' }}" required></label>
                    <label>Selling price markup (%)<input type="number" name="markup_percent" min="0" max="1000" step="any" value="{{ $details['markup_percent'] ?? '' }}" required data-review-markup></label>
                </div>
                <datalist id="review-categories">@foreach($categories as $category)<option value="{{ $category }}">@endforeach</datalist>
                <datalist id="review-shelves">@foreach($shelves as $shelf)<option value="{{ $shelf }}">@endforeach</datalist>
                <p>Supplier cost {{ \App\Support\Display::money($price->unit_price) }} · Selling price <output data-review-price data-cost="{{ $price->unit_price }}">—</output></p>
                <button class="apply-button" type="submit">Save &amp; next</button>
            @else
                <p>This supplier item was removed. Return to Supplier Price to start a new review.</p>
            @endif
        </section>
    </form>
    <p data-review-status role="status"></p>
    <form class="supplier-review-submit" method="POST" action="{{ route('admin.suppliers.prices.bulk-create-products') }}">
        @csrf
        <input type="hidden" name="batch" value="{{ $batch }}">
        <button class="apply-button" type="submit" data-review-create @disabled($readyCount !== count($entries))>Create products</button>
        <span data-review-unsaved hidden>Save your changes before creating products.</span>
    </form>
</main>
</body>
</html>
