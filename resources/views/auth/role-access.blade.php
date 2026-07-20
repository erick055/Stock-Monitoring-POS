<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Account Access</title>
    @vite(['resources/css/role-access.css', 'resources/js/role-access.js'])
</head>
<body>
<main class="auth-shell" data-old-mode="{{ old('auth_mode', 'login') }}" data-base-url="{{ request()->getBaseUrl() }}" data-has-errors="{{ $errors->any() ? 'true' : 'false' }}">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if($errors->any())
            <div class="error-box" role="alert"><strong>Please check your details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <p class="eyebrow" data-form-mode>WELCOME BACK</p>
        <h1 data-form-title>Log in to MotoSync</h1>
        <p class="subtitle" data-form-copy>Use your registered email address and password.</p>

        <form class="account-form" method="POST" action="{{ route('login.store') }}" data-account-form>
            @csrf
            <input type="hidden" name="auth_mode" value="{{ old('auth_mode', 'login') }}" data-mode-input>

            <label data-name-field hidden>
                Full name
                <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="100" placeholder="Enter your full name">
            </label>
            <label>
                Email address
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" placeholder="you@example.com" required autofocus>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
            </label>
            <label data-confirm-field hidden>
                Confirm password
                <input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Repeat your password">
            </label>
            <p class="password-hint" data-password-hint hidden>Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
            <label class="remember-field" data-remember-field><input type="checkbox" name="remember" value="1"> Remember me</label>
            <button class="primary-button" type="submit" data-submit-label>Log in</button>
            <p class="switch-copy"><span data-switch-text>Need a staff account?</span> <button type="button" data-switch-mode>Create one</button></p>
        </form>
    </section>
</main>
</body>
</html>
