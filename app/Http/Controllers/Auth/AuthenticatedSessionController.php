<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\TrustedLoginDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    public function store(Request $request, TrustedLoginDeviceService $trustedDevices): RedirectResponse
    {
        $request->merge([
            'email' => Str::lower(trim((string) $request->input('email'))),
        ]);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $throttleKey = Str::transliterate($credentials['email'].'|'.$request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            throw ValidationException::withMessages([
                'email' => "Too many login attempts. Try again in {$seconds} seconds.",
            ]);
        }

        $provider = Auth::guard()->getProvider();
        $user = $provider->retrieveByCredentials($credentials);

        if (! $user || ! $provider->validateCredentials($user, $credentials)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages(['email' => 'The provided credentials are incorrect.']);
        }

        RateLimiter::clear($throttleKey);

        if (! $user instanceof User || ! in_array($user->role, ['admin', 'staff'], true)) {
            throw ValidationException::withMessages(['email' => 'This account is not authorized to access the application.']);
        }

        if ($user->account_status !== 'active') {
            $message = $user->account_status === 'pending'
                ? 'Your staff registration is waiting for owner approval.'
                : 'This staff account has been disabled. Contact the owner for assistance.';

            throw ValidationException::withMessages(['email' => $message]);
        }

        if ($trustedDevices->isTrusted($request, $user)) {
            Auth::login($user, false);
            $request->session()->regenerate();
            $request->session()->put('auth.last_activity_at', now()->timestamp);
            Auth::guard()->getProvider()->updateRememberToken($user, Str::random(60));

            return redirect()->intended(route($user->role.'.dashboard'))
                ->withoutCookie(Auth::guard()->getRecallerName());
        }

        try {
            LoginVerificationController::issueCode($user);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages([
                'email' => 'We could not send your verification code. Please try again shortly.',
            ]);
        }

        $request->session()->put([
            'login_verification.user_id' => $user->id,
            'login_verification.started_at' => now()->timestamp,
        ]);

        return redirect()->route('login.verify');
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
