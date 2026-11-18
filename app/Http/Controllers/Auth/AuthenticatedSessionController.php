<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\SingleSessionLogin;
use App\Services\TrustedLoginDeviceService;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthenticatedSessionController extends Controller
{
    private const ACCOUNT_NOT_FOUND = 'Account not found.';

    private const WRONG_PASSWORD = 'Password is incorrect.';

    private const STAFF_ACCOUNT_LOCKED = 'Your staff account is locked after three failed login attempts. Contact an admin to unlock it.';

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

        $result = match (true) {
            ! $user => ['email' => self::ACCOUNT_NOT_FOUND],
            $user instanceof User && $user->role === 'staff' => $this->validateStaffCredentials($provider, $user, $credentials),
            ! $provider->validateCredentials($user, $credentials) => ['password' => self::WRONG_PASSWORD],
            default => $user,
        };

        if (is_array($result)) {
            RateLimiter::hit($throttleKey, 60);

            throw ValidationException::withMessages($result);
        }

        $user = $result;
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

        if ($user->role === 'admin' || $trustedDevices->isTrusted($request, $user)) {
            if ($user->role === 'admin') {
                // Owners use locally provisioned credentials without an email challenge.
                if (! $user->hasVerifiedEmail()) {
                    $user->markEmailAsVerified();
                }
                $user->loginVerificationCode()->delete();
            }
            app(SingleSessionLogin::class)->start($request, $user);

            if ($user->role === 'staff' && $user->must_change_password) {
                return redirect()->route('staff.password.edit')->withoutCookie(Auth::guard()->getRecallerName());
            }

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

    /** @return User|array<string, string> */
    private function validateStaffCredentials(UserProvider $provider, User $user, #[\SensitiveParameter] array $credentials): User|array
    {
        return DB::transaction(function () use ($provider, $user, $credentials): User|array {
            $current = User::query()->lockForUpdate()->find($user->id);
            if (! $current || $current->email !== $user->email || $current->role !== 'staff') {
                return ['email' => self::ACCOUNT_NOT_FOUND];
            }

            if ($current->isLockedByFailedLogins()) {
                return ['email' => self::STAFF_ACCOUNT_LOCKED];
            }

            $valid = $provider->validateCredentials($current, $credentials);
            if ($current->account_status !== 'active') {
                return $valid ? $current : ['password' => self::WRONG_PASSWORD];
            }

            if ($valid) {
                if ($current->failed_login_attempts > 0) {
                    $current->forceFill(['failed_login_attempts' => 0])->save();
                }

                return $current;
            }

            $current->failed_login_attempts++;
            if ($current->failed_login_attempts >= User::STAFF_LOGIN_FAILURE_LIMIT) {
                $current->forceFill([
                    'account_status' => 'disabled',
                    'disabled_by' => null,
                    'disabled_at' => now(),
                    'disabled_reason' => 'Automatically locked after three failed login attempts.',
                    'active_session_hash' => hash('sha256', Str::random(64)),
                    'remember_token' => Str::random(60),
                ]);
                $current->loginVerificationCode()->delete();
                $current->trustedLoginDevices()->delete();
            }
            $current->save();

            return $current->isLockedByFailedLogins()
                ? ['email' => self::STAFF_ACCOUNT_LOCKED]
                : ['password' => self::WRONG_PASSWORD];
        });
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
