<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MotoSync | Account Access</title>
    @vite(['resources/css/role-access.css', 'resources/js/role-access.js'])
</head>
<body>
<main class="auth-shell" data-old-role="{{ old('role') }}" data-old-mode="{{ old('auth_mode', 'register') }}" data-base-url="{{ request()->getBaseUrl() }}" data-has-errors="{{ $errors->any() ? 'true' : 'false' }}">
    <a class="brand" href="/"><span>M</span> MotoSync</a>
    <section class="auth-card">
        @if($errors->any())
            <div class="error-box" role="alert"><strong>Please check your details.</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
        @endif
        <div data-role-step>
            <p class="eyebrow">ACCOUNT ACCESS</p>
            <h1>Choose your role</h1>
            <p class="subtitle">Select how you will use MotoSync.</p>
            <div class="role-grid">
                <button class="role-option" type="button" data-role="admin"><span class="role-icon">A</span><strong>Admin</strong><small>Manage inventory, reports, users, and settings.</small></button>
                <button class="role-option" type="button" data-role="staff"><span class="role-icon">S</span><strong>Staff</strong><small>Process sales and handle daily stock operations.</small></button>
            </div>
            <button class="primary-button" type="button" data-open-register disabled>Create Account</button>
            <p class="switch-copy">Already registered? <button type="button" data-open-login>Log in</button></p>
        </div>
        <form class="account-form" method="POST" data-account-form hidden>
            @csrf
            <button class="back-button" type="button" data-back>← Choose another role</button>
            <p class="eyebrow" data-form-mode>CREATE ACCOUNT</p>
            <h2><span data-role-label>Admin</span> <span data-form-title>registration</span></h2>
            <p class="subtitle" data-form-copy>Create your MotoSync account.</p>
            <input type="hidden" name="role" value="{{ old('role') }}" data-role-input><input type="hidden" name="auth_mode" value="{{ old('auth_mode', 'register') }}" data-mode-input>
            <label data-name-field>Full name<input type="text" name="name" value="{{ old('name') }}" autocomplete="name" placeholder="Enter your full name"></label>
            <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" placeholder="you@example.com" required></label>
            <label>Password<input type="password" name="password" autocomplete="new-password" placeholder="Enter your password" required></label>
            <label data-confirm-field>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" placeholder="Repeat your password"></label>
            <label class="remember-field" data-remember-field hidden><input type="checkbox" name="remember" value="1"> Remember me</label>
            <button class="primary-button" type="submit" data-submit-label>Create account</button>
            <p class="switch-copy"><span data-switch-text>Already have an account?</span> <button type="button" data-switch-mode>Log in</button></p>
        </form>
    </section>
</main>
</body>
</html>
