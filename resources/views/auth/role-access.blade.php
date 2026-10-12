<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Account Access</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css', 'resources/js/role-access.js'])
</head>
<body>
<main class="auth-shell">getBaseUrl() }}" data-has-errors="{{ $errors->any() ? 'true' : 'false' }}">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if(session('status'))<div class="success-box" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())
            <div class="error-box" role="alert"><strong>Please check your details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif

        <p class="eyebrow" data-form-mode>WELCOME BACK</p>
        <h1 data-form-title>Log in to MotoSync</h1>
        <p class="subtitle" data-form-copy>Use your registered email address and password.</p>

        <form class="account-form" method="POST" action="{{ route('login.store') }}" data-account-form>
            @csrf

            <label>
                Email address
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" placeholder="you@example.com" required autofocus>
            </label>
            <label>
                Password
                <input type="password" name="password" autocomplete="current-password" placeholder="Enter your password" required>
            </label>
            <a class="forgot-link" href="{{ route('password.request') }}" data-login-only>Forgot password?</a>
            <p class="session-note">Inactive owner sessions expire after 60 minutes. Staff POS sessions expire after 30 days.</p>
            <button class="primary-button" type="submit" data-submit-label>Log in</button>
            <p class="switch-copy">Need a staff account? Contact the owner.</p>
        </form>
    </section>
</main>
</body>
</html>
