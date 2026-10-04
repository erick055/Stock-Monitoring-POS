<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\LoginVerificationCode;
use App\Models\User;
use App\Notifications\LoginVerificationCodeNotification;
use App\Services\TrustedLoginDeviceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginVerificationController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Your login verification session has expired. Please log in again.']);
        }

        return view('auth.login-verification', ['maskedEmail' => $this->maskEmail($user->email)]);
    }

    public function store(Request $request, TrustedLoginDeviceService $trustedDevices): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $user = $this->pendingUser($request);
        $outcome = DB::transaction(function () use ($request, $user): string {
            if (! $user) return 'expired';
            // All code issue/resend/verification requests serialize on the user row.
            $lockedUser = User::query()->lockForUpdate()->find($user->id);
            $verification = LoginVerificationCode::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser || $lockedUser->account_status !== 'active'
                || ! in_array($lockedUser->role, ['admin', 'staff'], true)) {
                $verification?->delete();
                return 'expired';
            }
            if (! $verification || $verification->expires_at->lte(now())) {
                $verification?->delete();
                return 'expired';
            }
            if ($verification->attempts >= 5) {
                $verification->delete();
                return 'locked';
            }
            if (! Hash::check((string) $request->input('code'), $verification->code_hash)) {
                $verification->increment('attempts');
                if ($verification->attempts >= 5) {
                    $verification->delete();
                    return 'locked';
                }
                // Return normally so the failed-attempt update commits.
                return 'incorrect';
            }
            $verification->delete();
            return 'verified';
        });

        if ($outcome === 'incorrect') {
            throw ValidationException::withMessages(['code' => 'The verification code is incorrect.']);
        }
        if ($outcome !== 'verified') {
            $this->clearPendingLogin($request);
            return redirect()->route('login')->withErrors(['email' => $outcome === 'locked'
                ? 'Too many incorrect codes. Please log in again.'
                : 'Your login code has expired. Please log in again.']);
        }

        $request->session()->forget(['login_verification.user_id', 'login_verification.started_at']);
        Auth::login($user, false);
        $request->session()->regenerate();
        $request->session()->put('auth.last_activity_at', now()->timestamp);
        Auth::guard()->getProvider()->updateRememberToken($user, Str::random(60));
        $trustedDevices->trust($request, $user);

        return redirect()->intended(route($user->role.'.dashboard'))
            ->withoutCookie(Auth::guard()->getRecallerName());
    }

    public function resend(Request $request): RedirectResponse
    {
        $user = $this->pendingUser($request);
        $outcome = DB::transaction(function () use ($user): string {
            if (! $user) return 'expired';
            $lockedUser = User::query()->lockForUpdate()->find($user->id);
            $verification = LoginVerificationCode::where('user_id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser || $lockedUser->account_status !== 'active' || ! $verification || $verification->attempts >= 5) return 'expired';
            if ($verification->last_sent_at->addSeconds(60)->isFuture()) return 'wait';
            $this->issueCode($lockedUser);
            return 'sent';
        });

        if ($outcome === 'expired') {
            $this->clearPendingLogin($request);
            return redirect()->route('login')->withErrors(['email' => 'Your login verification session has expired. Please log in again.']);
        }

        if ($outcome === 'wait') {
            return back()->withErrors(['code' => 'Please wait 60 seconds before requesting another code.']);
        }

        return back()->with('status', 'A new verification code has been sent.');
    }

    public static function issueCode(User $user): void
    {
        $code = (string) random_int(100000, 999999);

        DB::transaction(function () use ($user, $code): void {
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            abort_unless($lockedUser->account_status === 'active', 403);
            LoginVerificationCode::updateOrCreate(
            ['user_id' => $user->id],
            [
                'code_hash' => Hash::make($code),
                'attempts' => 0,
                'expires_at' => now()->addMinutes(10),
                'last_sent_at' => now(),
            ],
            );
            // A mail failure rolls back this issuance rather than clearing another request's code.
            $lockedUser->notify(new LoginVerificationCodeNotification($code));
        });

    }

    private function pendingUser(Request $request): ?User
    {
        $startedAt = (int) $request->session()->get('login_verification.started_at', 0);

        if (! $startedAt || $startedAt < now()->subMinutes(10)->timestamp) {
            return null;
        }

        return User::find($request->session()->get('login_verification.user_id'));
    }

    private function clearPendingLogin(Request $request): void
    {
        $request->session()->forget(['login_verification.user_id', 'login_verification.started_at']);
    }

    private function maskEmail(string $email): string
    {
        [$name, $domain] = explode('@', $email, 2);

        return Str::substr($name, 0, 1).str_repeat('•', max(2, Str::length($name) - 1)).'@'.$domain;
    }
}
