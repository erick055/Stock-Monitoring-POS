<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Verify Email</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css', 'resources/js/email-verification.js'])
</head>
<body>
<main class="auth-shell">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card" data-email-verification data-status-url="{{ route('verification.status') }}" data-login-url="{{ route('login') }}">
        <p class="eyebrow">EMAIL SECURITY</p>
        <h1>Verify your email</h1>
        <p class="subtitle">
            We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
            You can open the link on this device or another device. This page will continue automatically once verified.
        </p>

        @if (session('status') === 'verification-link-sent')
            <div class="success-box" role="status">A new verification link has been sent.</div>
        @endif

        <p class="session-note" data-verification-status role="status">Waiting for email verification…</p>
        <a class="secondary-button" href="{{ route('verification.notice') }}">I've verified my email — continue</a>
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf
            <button class="primary-button" type="submit">Resend verification email</button>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button class="secondary-button" type="submit">Log out</button>
        </form>
    </section>
</main>
</body>
</html>
