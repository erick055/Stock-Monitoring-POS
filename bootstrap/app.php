<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [
            \App\Http\Middleware\EnforceIdleSessionTimeout::class,
        ]);

        $middleware->redirectUsersTo(function (\Illuminate\Http\Request $request): string {
            return match ($request->user()?->role) {
                'admin' => route('admin.dashboard'),
                'staff' => route('staff.dashboard'),
                default => route('login'),
            };
        });

        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

