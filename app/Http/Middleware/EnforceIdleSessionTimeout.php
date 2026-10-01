<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleSessionTimeout
{
    private const SESSION_KEY = 'auth.last_activity_at';

    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $lastActivity = (int) $request->session()->get(self::SESSION_KEY, 0);
            $role = (string) $request->user()->role;
            $idleMinutes = max(1, (int) config("session.idle_timeout_by_role.{$role}", 60));
            $idleSeconds = $idleMinutes * 60;
            $expired = $lastActivity > 0 && now()->timestamp - $lastActivity >= $idleSeconds;

            if ($expired || Auth::viaRemember()) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with(
                    'status',
                    'You were logged out after '.($role === 'staff' ? '30 days' : '60 minutes').' of inactivity. Please log in again.'
                );
            }

            $backgroundRequest = $request->routeIs('inventory.live')
                || ($request->isMethod('GET') && $request->query('_background') === '1');
            if (! $backgroundRequest || ! $lastActivity) {
                $request->session()->put(self::SESSION_KEY, now()->timestamp);
            }
        }

        return $next($request);
    }
}
