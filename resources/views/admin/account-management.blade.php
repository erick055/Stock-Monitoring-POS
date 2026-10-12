@php
$navigation = [
    ['house-door','Dashboard','/admin/dashboard'], ['boxes','Stock Management','/admin/inventory'], ['box-seam','Products','/admin/products'], ['cart3','POS Checkout','/admin/pos'],
    ['bar-chart-line','Analytics','/admin/analytics'], ['exclamation-triangle','Low Stock Alerts','/admin/low-stocks'], ['box2','Dead Stock','/admin/deadstock'],
    ['arrow-repeat','Returns & Damages','/admin/returns'], ['tags','Supplier Price','/admin/suppliers'], ['gear','Part Compatibility','/admin/compatibility'],
    ['people','Account Management','#'],
];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Management | MotoSync</title>
    @vite(['resources/css/dashboard.css','resources/css/account-management.css','resources/css/responsive.css','resources/js/dashboard.js'])
</head>
<body>
<div class="dashboard-shell">
    <aside class="sidebar" data-sidebar>
        <div class="sidebar-brand"><span class="logo-mark">M</span><div><strong>MotoSync</strong><small>Pareng RJJ Motorcycle Parts</small></div></div>
        <nav class="nav-list" aria-label="Administrator navigation">
            @foreach($navigation as $index => $item)
                <a class="nav-link {{ $index === 10 ? 'active' : '' }}" href="{{ $item[2] === '#' ? '#' : url($item[2]) }}"><span><i class="bi bi-{{ $item[0] }}" aria-hidden="true"></i></span><span>{{ $item[1] }}</span></a>
            @endforeach
        </nav>
        <div class="sidebar-user">
            <div class="avatar">{{ collect(preg_split('/\s+/', auth()->user()->name))->take(2)->map(fn($word) => strtoupper(substr($word, 0, 1)))->join('') }}</div>
            <div><strong>{{ auth()->user()->name }}</strong><small>Owner</small></div>
            <form method="POST" action="{{ route('logout') }}">@csrf<button class="logout-button" type="submit" title="Log out"><i class="bi bi-box-arrow-right" aria-hidden="true"></i></button></form>
        </div>
    </aside>

    <main class="dashboard-main accounts-main">
        <header class="accounts-header">
            <button class="menu-button" type="button" data-menu aria-label="Toggle navigation"><i class="bi bi-list" aria-hidden="true"></i></button>
            <div><p class="welcome">OWNER CONTROL</p><h1>Account Management</h1><p>Create staff accounts, edit their details and passwords, and manage access.</p></div>
        </header>

        @if(session('success'))<div class="account-message success" role="status">{{ session('success') }}</div>@endif
        @if($errors->any())<div class="account-message error" role="alert">{{ $errors->first() }}</div>@endif

        <section class="stat-grid accounts-stats" aria-label="Account summary">
            <article class="stat-card purple"><div class="stat-head"><span>TOTAL STAFF</span></div><strong>{{ number_format($summary->total ?? 0) }}</strong><small>All staff accounts</small></article>
            <article class="stat-card orange"><div class="stat-head"><span>PENDING APPROVAL</span></div><strong>{{ number_format($summary->pending ?? 0) }}</strong><small>Waiting for owner review</small></article>
            <article class="stat-card cyan"><div class="stat-head"><span>ACTIVE STAFF</span></div><strong>{{ number_format($summary->active ?? 0) }}</strong><small>Can currently access MotoSync</small></article>
            <article class="stat-card red"><div class="stat-head"><span>DISABLED</span></div><strong>{{ number_format($summary->disabled ?? 0) }}</strong><small>Access has been removed</small></article>
        </section>

        <section class="panel accounts-panel account-create-panel">
            <div class="section-heading"><div><span class="section-kicker">OWNER ONLY</span><h2>Create staff account</h2></div></div>
            <p class="account-form-note">Staff access is active immediately. The staff member must verify their email before accessing the system.</p>
            @if($errors->createAccount->any())<div class="account-message error" role="alert"><ul>@foreach($errors->createAccount->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
            <form class="staff-account-form" method="POST" action="{{ route('admin.accounts.store') }}">
                @csrf
                <label>Full name<input name="name" value="{{ $errors->createAccount->any() ? old('name') : '' }}" minlength="2" maxlength="100" autocomplete="name" required></label>
                <label>Email address<input type="email" name="email" value="{{ $errors->createAccount->any() ? old('email') : '' }}" maxlength="255" autocomplete="email" required></label>
                <label>Password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
                <label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
                <p class="account-form-note">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
                <button type="submit">Create staff account</button>
            </form>
        </section>

        <section class="panel accounts-panel">
            <div class="section-heading"><div><span class="section-kicker">STAFF ACCESS</span><h2>Staff accounts and status</h2></div></div>
            <form class="account-toolbar" method="GET">
                <label><span>Search</span><input type="search" name="search" value="{{ $search }}" placeholder="Name or email"></label>
                <label><span>Status</span><select name="status"><option value="all">All accounts</option>@foreach(['pending','active','disabled'] as $option)<option value="{{ $option }}" @selected($status === $option)>{{ ucfirst($option) }}</option>@endforeach</select></label>
                <button type="submit">Apply filters</button>
                @if($search || $status !== 'all')<a href="{{ route('admin.accounts') }}">Reset</a>@endif
            </form>

            <div class="account-list">
                @forelse($staff as $account)
                    <article class="account-card {{ $account->account_status }}">
                        <div class="account-identity">
                            <span class="account-avatar" role="img" aria-label="User profile"><i class="bi bi-person-fill" aria-hidden="true"></i></span>
                            <div><strong>{{ $account->name }}</strong><span>{{ $account->email }}</span><small>Registered {{ $account->created_at->format('M d, Y · h:i A') }}</small></div>
                        </div>
                        <div class="account-state">
                            <span class="status-pill {{ $account->account_status }}">{{ ucfirst($account->account_status) }}</span>
                            @if($account->approved_at)<small>Approved {{ $account->approved_at->diffForHumans() }} by {{ $account->approvedBy?->name ?? 'Owner' }}</small>@endif
                            @if($account->disabled_at)<small>Disabled {{ $account->disabled_at->diffForHumans() }} by {{ $account->disabledBy?->name ?? 'Owner' }}</small>@endif
                            @if($account->disabled_reason)<p><strong>Reason:</strong> {{ $account->disabled_reason }}</p>@endif
                        </div>
                        <div class="account-actions">
                            @if($account->account_status === 'pending')
                                <form method="POST" action="{{ route('admin.accounts.approve', $account) }}">@csrf @method('PATCH')<button class="approve-button" type="submit">Approve as staff</button></form>
                                <form class="disable-form" method="POST" action="{{ route('admin.accounts.disable', $account) }}">@csrf @method('PATCH')<input name="disabled_reason" maxlength="500" required placeholder="Reason for declining access"><button class="disable-button" type="submit">Decline</button></form>
                            @elseif($account->account_status === 'active')
                                <form class="disable-form" method="POST" action="{{ route('admin.accounts.disable', $account) }}">@csrf @method('PATCH')<input name="disabled_reason" maxlength="500" required placeholder="Reason for disabling access"><button class="disable-button" type="submit">Disable staff access</button></form>
                            @else
                                <form method="POST" action="{{ route('admin.accounts.reactivate', $account) }}">@csrf @method('PATCH')<button class="reactivate-button" type="submit">Restore staff access</button></form>
                            @endif
                        </div>
                        <details class="account-edit" @if($errors->getBag('editAccount'.$account->id)->any()) open @endif>
                            <summary>Edit staff account</summary>
                            @if($errors->getBag('editAccount'.$account->id)->any())<div class="account-message error" role="alert"><ul>@foreach($errors->getBag('editAccount'.$account->id)->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                            <form class="staff-account-form" method="POST" action="{{ route('admin.accounts.update', $account) }}">
                                @csrf @method('PATCH')
                                <label>Full name<input name="name" value="{{ $errors->getBag('editAccount'.$account->id)->any() ? old('name', $account->name) : $account->name }}" minlength="2" maxlength="100" required></label>
                                <label>Email address<input type="email" name="email" value="{{ $errors->getBag('editAccount'.$account->id)->any() ? old('email', $account->email) : $account->email }}" maxlength="255" required></label>
                                <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password" placeholder="Leave blank to keep current password"></label>
                                <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password"></label>
                                <p class="account-form-note">Passwords require 12 characters, uppercase, lowercase, a number, and a symbol. Changing email or password signs staff out. A changed email requires verification.</p>
                                <button type="submit">Save staff account</button>
                            </form>
                        </details>
                    </article>
                @empty
                    <div class="empty-accounts"><strong>No staff accounts found</strong><span>Create a staff account above to grant access.</span></div>
                @endforelse
            </div>
            @if($staff->hasPages())<div class="account-pagination">{{ $staff->links() }}</div>@endif
        </section>
    </main>
</div>
@include('partials.login-stock-alert')
</body>
</html>
