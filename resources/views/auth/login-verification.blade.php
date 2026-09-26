<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Verify Login</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css'])
</head>
<body>
<main class="auth-shell">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if(session('status'))<div class="success-box" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error-box" role="alert"><strong>We couldn't verify that code.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <p class="eyebrow">LOGIN SECURITY</p>
        <h1>Check your email</h1>
        <p class="subtitle">Enter the six-digit code sent to <strong>{{ $maskedEmail }}</strong>. It expires in 10 minutes.</p>

        <form class="account-form" method="POST" action="{{ route('login.verify.store') }}">
            @csrf
            <label>Verification code<input class="verification-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus></label>
            <button class="primary-button" type="submit">Verify and log in</button>
        </form>
        <form method="POST" action="{{ route('login.verify.resend') }}">
            @csrf
            <button class="secondary-button" type="submit">Resend code</button>
        </form>
        <p class="switch-copy"><a class="text-link" href="{{ route('login') }}">Back to login</a></p>
    </section>
</main>
</body>
</html>
