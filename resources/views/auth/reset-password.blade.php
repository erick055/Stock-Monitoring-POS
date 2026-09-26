<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Reset Password</title>
    @vite(['resources/css/role-access.css', 'resources/css/responsive.css'])
</head>
<body>
<main class="auth-shell">
    <a class="brand" href="{{ route('login') }}"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if($errors->any())<div class="error-box" role="alert"><strong>Please check your details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

        <p class="eyebrow">ACCOUNT RECOVERY</p>
        <h1>Choose a new password</h1>
        <p class="subtitle">Create a strong password you haven't used for this account before.</p>

        <form class="account-form" method="POST" action="{{ route('password.update') }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <label>Email address<input type="email" name="email" value="{{ old('email', $email) }}" autocomplete="email" maxlength="255" required autofocus></label>
            <label>New password<input type="password" name="password" autocomplete="new-password" placeholder="Enter a new password" required></label>
            <label>Confirm new password<input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Repeat your new password" required></label>
            <p class="password-hint">Use at least 12 characters with uppercase, lowercase, a number, and a symbol.</p>
            <button class="primary-button" type="submit">Reset password</button>
            <p class="switch-copy"><a class="text-link" href="{{ route('login') }}">Back to login</a></p>
        </form>
    </section>
</main>
</body>
</html>
