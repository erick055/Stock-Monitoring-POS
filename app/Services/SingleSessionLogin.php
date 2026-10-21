<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SingleSessionLogin
{
    public const SESSION_KEY = 'auth.active_session_token';

    public function start(Request $request, User $user): void
    {
        DB::transaction(function () use ($request, $user) {
            $current = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($current->account_status !== 'active' || ! in_array($current->role, ['admin', 'staff'], true)
                || ! hash_equals($current->getAuthPassword(), $user->getAuthPassword())) {
                throw ValidationException::withMessages(['email' => 'Your account changed during login. Please log in again.']);
            }
            $token = Str::random(64);
            $current->forceFill([
                'active_session_hash' => hash('sha256', $token),
                'remember_token' => Str::random(60),
            ])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $current->id)->delete();
            }
            Auth::login($current, false);
            $request->session()->regenerate();
            $request->session()->forget(['login_verification.user_id', 'login_verification.started_at']);
            $request->session()->put(self::SESSION_KEY, $token);
            $request->session()->put('auth.last_activity_at', now()->timestamp);
            $request->session()->put('stock.login_alert_pending', $current->role === 'admin');
        });
    }
}
