<!DOCTYPE html>
<html lang="en">
<head>
    @include('partials.session-activity')
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Review supplier stock | MotoSync</title>
    @vite(['resources/css/dashboard.css', 'resources/css/suppliers.css', 'resources/css/responsive.css', 'resources/js/suppliers.js'])
    @include('partials.appearance')
</head>
<body>
<main class="dashboard-main supplier-review">
    <header class="section-heading">
        <div>
            <a href="{{ $backUrl }}">Back to Supplier Price</a>
            <h1>{{ $import ? 'Review stock additions' : 'Review product match and stock' }}</h1>
        </div>
    </header>
    <div data-stock-review-content>
    @if($import)<p>{{ $import->source_filename }}</p>@endif
    @if(session('success'))<p class="supplier-message success" role="status">{{ session('success') }}</p>@endif
    @if($errors->any())
        <div class="supplier-message error" role="alert">
            <ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    @if($prices->isEmpty())
        <p>No matched products are available for stock additions from this import.</p>
    @else
        <form class="panel supplier-review-details supplier-stock-review" method="POST" action="{{ $action }}" data-supplier-stock-review>
            @csrf
            <p>Quantities start with the displayed supplier stock. Enter the units your shop actually received.</p>
            <p>{{ $import ? 'Enter 0 to skip an item. Confirmed units will be added to Stock Management.' : 'Enter 0 to save the match only. Confirmed units will be added to Stock Management.' }}</p>
            <div class="supplier-table-wrap">
                <table>
                    <thead>
                        <tr><th>Supplier item</th><th>Matched product</th><th>Supplier stock</th><th>Current stock</th><th>Units to add</th><th>Stock after addition</th></tr>
                    </thead>
                    <tbody>
                    @foreach($prices as $price)
                        @php
                            $product = $price->product;
                            $quantity = $price->stock_applied_at ? 0 : old("rows.{$price->getKey()}.quantity", max(0, (int) $price->available_quantity));
                        @endphp
                        <tr data-stock-review-row data-product-id="{{ $product->getKey() }}" data-current-stock="{{ $product->current_stock }}">
                            <td>{{ $price->product_name }}<small>{{ $price->supplier_sku }}</small></td>
                            <td>{{ $product->name }}<small>{{ $product->sku }}</small></td>
                            <td>{{ $price->available_quantity ?? 'Unknown' }}</td>
                            <td>{{ $product->current_stock }}</td>
                            <td>
                                <input type="hidden" name="rows[{{ $price->getKey() }}][price_id]" value="{{ $price->getKey() }}">
                                <input type="hidden" name="rows[{{ $price->getKey() }}][product_id]" value="{{ $product->getKey() }}">
                                <input type="hidden" name="rows[{{ $price->getKey() }}][version]" value="{{ $price->latestHistory?->getKey() ?? 0 }}">
                                <input type="hidden" name="rows[{{ $price->getKey() }}][current_stock]" value="{{ $product->current_stock }}">
                                <input type="number" name="rows[{{ $price->getKey() }}][quantity]" aria-label="Units to add for {{ $price->supplier_sku }}" min="0" max="2147483647" step="1" value="{{ is_scalar($quantity) ? $quantity : 0 }}" required @readonly($price->stock_applied_at) data-stock-review-quantity>
                                @if($price->stock_applied_at)<small>Stock already added for this supplier entry.</small>@endif
                            </td>
                            <td><output data-stock-review-balance>{{ $product->current_stock + (int) $quantity }}</output></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <button class="apply-button" type="submit">{{ $import ? 'Confirm stock additions' : 'Confirm match and stock' }}</button>
        </form>
        @if($import)<div data-stock-review-pagination>{{ $prices->links() }}</div>@endif
    @endif
    </div>
</main>
</body>
</html>
