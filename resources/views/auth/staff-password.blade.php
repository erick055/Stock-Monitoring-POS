<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="session-activity-url" content="{{ route('session.activity') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Change Your Password | MotoSync</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css', 'resources/js/role-access.js'])
</head>
<body>
<main class="auth-shell">
    <dialog open class="auth-card password-change-popup" aria-modal="true" aria-labelledby="password-change-title" aria-describedby="password-change-description">
        <p class="eyebrow">FIRST LOGIN · PASSWORD REQUIRED</p>
        <h1 id="password-change-title">Create your own password</h1>
        <p class="subtitle" id="password-change-description">Your owner provided a temporary password. Change it before using your staff account.</p>
        @if($errors->any())<div class="error-box" role="alert"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        <form class="account-form" method="POST" action="{{ route('staff.password.update') }}">
            @csrf
            <label>Temporary password<input type="password" name="current_password" autocomplete="current-password" required autofocus></label>
            <label>New password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label>
            <label>Confirm new password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label>
            <p class="password-hint">Use at least 12 characters with uppercase, lowercase, a number, and a symbol. Choose a password different from your temporary password.</p>
            <button class="primary-button" type="submit">Change password and continue</button>
        </form>
        <form class="password-change-logout" method="POST" action="{{ route('logout') }}">@csrf<button type="submit">Log out</button></form>
    </dialog>
</main>
</body>
</html>
