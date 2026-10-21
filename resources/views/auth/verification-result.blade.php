<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $verified ? 'Email Verified' : 'Verification Link Unavailable' }} | MotoSync</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css'])
</head>
<body>
<main class="auth-shell">
    <section class="auth-card">
        <p class="eyebrow">EMAIL VERIFICATION</p>
        <h1>{{ $verified ? 'Email verified' : 'This verification link is unavailable' }}</h1>
        @if($verified)
            <div class="success-box" role="status">Your email has been verified successfully.</div>
            <p class="subtitle">Return to the device where you signed in. Its verification page will continue automatically. You can close this tab.</p>
        @else
            <div class="error-box" role="alert">This link may have expired, been changed, or belong to an account that is no longer active.</div>
            <p class="subtitle">Return to the verification page on your main device and request a new email. Contact the owner if your account is disabled.</p>
        @endif
        @guest
            <a class="primary-button" href="{{ route('login') }}">Go to login</a>
        @else
            <p class="session-note">The account signed in on this browser has not been changed.</p>
        @endguest
    </section>
</main>
</body>
</html>
