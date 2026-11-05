<?php

namespace App\Http\Middleware;

use App\Services\SingleSessionLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureCurrentLoginSession
{
    public function handle(Request $request, Closure $next): Response
    {
        // Public signed email links must still work from an old browser session.
        if ($request->routeIs('verification.verify')) {
            return $next($request);
        }
        $user = $request->user();
        if ($user?->active_session_hash) {
            $token = (string) $request->session()->get(SingleSessionLogin::SESSION_KEY, '');
            if (! $token || ! hash_equals($user->active_session_hash, hash('sha256', $token))) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $message = 'You signed in on another device. Please log in again.';
                $request->session()->flash('status', $message);
                if ($request->expectsJson()) {
                    return response()->json(['authenticated' => false, 'reason' => 'session_replaced', 'message' => $message, 'login_url' => route('login')], 401);
                }

                return redirect()->route('login')->with('status', $message);
            }
        }

        return $next($request);
    }
}
