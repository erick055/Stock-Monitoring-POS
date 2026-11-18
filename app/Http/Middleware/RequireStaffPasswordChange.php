<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireStaffPasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role === 'staff' && $user->must_change_password
            && ! $request->routeIs('staff.password.*', 'logout', 'verification.*', 'session.status')) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Change your temporary password before using the system.',
                    'password_change_url' => route('staff.password.edit'),
                ], 403);
            }

            return redirect()->route('staff.password.edit');
        }

        return $next($request);
    }
}
