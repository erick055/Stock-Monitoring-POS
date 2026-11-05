<?php

namespace App\Http\Middleware;

use App\Services\SessionActivity;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceIdleSessionTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::check()) {
            return $next($request);
        }
        $activity = app(SessionActivity::class);
        $lastActivity = (int) $request->session()->get(SessionActivity::KEY, 0);
        $idleMinutes = $activity->minutes($request->user());
        $expired = $lastActivity > 0 && now()->timestamp - $lastActivity >= $idleMinutes * 60;
        if ($expired || Auth::viaRemember()) {
            $idleLabel = $idleMinutes % 1440 === 0 ? ($idleMinutes / 1440).' days' : $idleMinutes.' minutes';
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $message = 'You were logged out after '.$idleLabel.' of inactivity. Please log in again.';
            $request->session()->flash('status', $message);
            if ($request->expectsJson()) {
                return response()->json(['authenticated' => false, 'reason' => 'idle_timeout', 'message' => $message, 'login_url' => route('login')], 401);
            }

            return redirect()->route('login')->with('status', $message);
        }
        $background = $request->routeIs('inventory.live', 'verification.status', 'session.status')
            || ($request->isMethod('GET') && $request->query('_background') === '1');
        // Publish the proposed deadline to rendered HTML, then commit it only if
        // the request succeeds. Status and inventory polls never count as activity.
        $activityAt = ! $background || ! $lastActivity ? now()->timestamp : $lastActivity;
        $request->attributes->set(SessionActivity::KEY, $activityAt);
        $response = $next($request);
        if (Auth::check() && $response->getStatusCode() < 400) {
            $request->session()->put(SessionActivity::KEY, $activityAt);
            $snapshot = $activity->snapshot($request);
            $response->headers->set('X-Session-Expires-At', (string) $snapshot['expires_at']);
            $response->headers->set('X-Session-Timeout', (string) $snapshot['idle_timeout_seconds']);
            $response->headers->set('X-Session-Time', (string) $snapshot['server_time']);
        }

        return $response;
    }
}
