<?php

use App\Http\Middleware\EnforceIdleSessionTimeout;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureCurrentLoginSession;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\RequireStaffPasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(SecurityHeaders::class);
        $middleware->authenticateSessions();
        $middleware->appendToGroup('web', [
            EnsureAccountIsActive::class,
            EnsureCurrentLoginSession::class,
            EnforceIdleSessionTimeout::class,
            RequireStaffPasswordChange::class,
        ]);

        $middleware->redirectUsersTo(function (Request $request): string {
            return match ($request->user()?->role) {
                'admin' => route('admin.dashboard'),
                'staff' => route('staff.dashboard'),
                default => route('login'),
            };
        });

        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->respond(fn ($response) => SecurityHeaders::apply($response, request()));
    })->create();
