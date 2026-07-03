@php
$navigation = [
    ['⌂','Dashboard','/staff/dashboard'], ['▣','Stock Management','/staff/stock-management'], ['□','Products','/staff/products'],
    ['▤','POS Checkout','/staff/pos'], ['◇','Return & Damage','/staff/returns'], ['⚙','Part Compatibility','#'],
];
$models = [
    ['Honda Click v3', '110cc - 2024'],
    ['Yamaha NMAX V2', '155cc - 2023'],
    ['Honda Beat FI', '110cc - 2023'],
    ['Suzuki Burgman', '125cc - 2022'],
];
$compatibleParts = [
    ['Engine Oil 1L', 'Lubricants'],
    ['Spark Plug', 'Engine'],
    ['Oil Filter', 'Maintenance'],
    ['Brake Pads', 'Brakes'],
];
$maintenance = [
    ['Oil Change', 'Parts Needed: Engine Oil 1L'],
    ['Brake Pad Check', 'Parts Needed: Brake Pads'],
    ['Air Filter Clean', 'Parts Needed: Air Filter'],
    ['Chain Lubrication', 'Parts Needed: Chain Lubricant'],
];
$history = [
    ['John Carol Manluieto Coco Crisolog', 'Honda Click v3', 'Oil Change - 2026-06-30', 'Next Service Due: Air Filter in 4 Days'],
    ['John Carol Manluieto Coco Crisolog', 'Honda Click v3', 'Oil Change - 2026-06-01', 'Next Service Due: Air Filter in 9 Days'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff Part Compatibility | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/compatibility.css','resources/js/dashboard.js','resources/js/compatibility.js'])
</head>
<body>
<div class="dashboard-shell compatibility-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Staff navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 5 ? 'active' : '' }}" href="{{ $item[2] }}"><span>{{ $item[0] }}</span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <span class="avatar">{{ strtoupper(substr(auth()->user()->name,0,2)) }}</span>
            <div><strong>{{ auth()->user()->name }}</strong><small>Staff</small></div>
            <form method="POST" action="{{ request()->getBaseUrl() }}/logout">@csrf<button class="logout-button" type="submit" title="Log out">&#8618;</button></form>
        </div>
    </aside>

    <main class="dashboard-main compatibility-main">
        <header class="compatibility-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation">&#9776;</button>
            <div><p class="welcome">SMART PART SUGGESTION AND MAINTENANCE TRACKING</p><h1>Part Compatibility</h1><p>Match products by motorcycle model and track recommended maintenance parts.</p></div>
        </header>

        <section class="panel search-panel">
            <div class="section-heading"><div><span class="section-kicker">MODEL SEARCH</span><h2>Search by Motorcycle Model</h2></div></div>
            <div class="search-grid">
                <label>Brand<select><option>Honda</option><option>Yamaha</option><option>Suzuki</option></select></label>
                <label>Model<input type="text" value="Click v3"></label>
                <label>Year<input type="text" value="2024"></label>
                <div class="search-action-wrap"><button class="search-action" type="button">Search</button></div>
            </div>
            <div class="popular-grid">
                @foreach($models as $model)
                    <article class="popular-card"><strong>{{ $model[0] }}</strong><small>{{ $model[1] }}</small></article>
                @endforeach
            </div>
        </section>

        <section class="compatibility-grid">
            <section class="panel compatibility-panel">
                <div class="section-heading"><div><span class="section-kicker">MATCHED PRODUCTS</span><h2>Compatible Parts for Honda Click v3(2024)</h2></div></div>
                <div class="parts-grid">
                    @foreach($compatibleParts as $part)
                        <article class="part-card"><strong>{{ $part[0] }}</strong><small>{{ $part[1] }}</small></article>
                    @endforeach
                </div>
            </section>

            <section class="panel compatibility-panel">
                <div class="section-heading"><div><span class="section-kicker">SERVICE PLAN</span><h2>Suggested Maintenance Schedule</h2></div></div>
                <div class="maintenance-grid">
                    @foreach($maintenance as $item)
                        <article class="maintenance-card"><strong>{{ $item[0] }}</strong><small>{{ $item[1] }}</small></article>
                    @endforeach
                </div>
            </section>
        </section>

        <section class="panel history-panel">
            <div class="section-heading"><div><span class="section-kicker">CUSTOMER RECORDS</span><h2>Customer Maintenance History</h2></div><button class="apply-button" type="button" data-refresh-compatibility>Refresh</button></div>
            <div class="history-list">
                @foreach($history as $item)
                    <article class="history-card"><div><strong>{{ $item[0] }}</strong><small>{{ $item[1] }}</small></div><div><small>{{ $item[2] }}</small><strong>{{ $item[3] }}</strong></div></article>
                @endforeach
            </div>
        </section>

        <div class="compatibility-toast" data-compatibility-toast hidden role="status">Compatibility records refreshed in UI preview.</div>
    </main>
</div>
</body>
</html>
