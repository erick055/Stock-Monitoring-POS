<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Forgot Password</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css'])
</head>
<body>
<main class="auth-shell">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if(session('status'))<div class="success-box" role="status">{{ session('status') }}</div>@endif
        @if($errors->any())<div class="error-box" role="alert"><strong>We couldn't send the link.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <p class="eyebrow">ACCOUNT RECOVERY</p>
        <h1>Forgot your password?</h1>
        <p class="subtitle">Enter your account email and we'll send you a secure password reset link.</p>

        <form class="account-form" method="POST" action="{{ route('password.email') }}">
            @csrf
            <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" maxlength="255" placeholder="you@example.com" required autofocus></label>
            <button class="primary-button" type="submit">Email reset link</button>
            <p class="switch-copy"><a class="text-link" href="{{ route('login') }}">Back to login</a></p>
        </form>
    </section>
</main>
</body>
</html>
