<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerIsolatedRateLimits();
        RateLimiter::for('inventory-archive', fn (Request $request) =>
            Limit::perMinute(10)->by((string) $request->user()->id)->response(function (Request $request, array $headers) {
                $message = 'Please wait before archiving more products. No products were changed.';
                if ($request->expectsJson()) return response()->json(['message' => $message], 429, $headers);
                return redirect()->route('admin.inventory')->withErrors(['archive' => $message])->withHeaders($headers);
            }));
        RateLimiter::for('email-verification-status', fn (Request $request) =>
            Limit::perMinute(120)->by((string) $request->user()->id));
        RateLimiter::for('email-verification-resend', function (Request $request) {
            return Limit::perMinute(1)->by((string) $request->user()->id)
                ->response(function (Request $request, array $headers) {
                    $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                    $message = "Please wait {$seconds} seconds before requesting another verification email.";
                    if ($request->expectsJson()) return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
                    return redirect()->route('verification.notice')->withErrors(['verification' => $message])->withHeaders($headers);
                });
        });
        foreach (['staff-account-changes' => 20, 'staff-account-deletion' => 10] as $name => $attempts) {
            RateLimiter::for($name, function (Request $request) use ($attempts) {
                return Limit::perMinute($attempts)->by((string) $request->user()->id)
                    ->response(function (Request $request, array $headers) {
                        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                        $message = "Please wait {$seconds} seconds before another account action. No changes were made.";
                        if ($request->expectsJson()) return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
                        return redirect()->route('admin.accounts')->withErrors(['account_rate_limit' => $message])->withHeaders($headers);
                    });
            });
        }
        RateLimiter::for('compatibility-search', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->user()->id)->response(function (Request $request, array $headers) {
                $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                $message = "Please wait {$seconds} seconds before another compatibility search. Your search details have been kept.";
                if ($request->expectsJson()) return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
                return redirect()->route($request->user()->role.'.compatibility')
                    ->withInput($request->only('brand', 'model', 'year', 'part_search'))
                    ->withErrors(['compatibility' => $message])->withHeaders($headers);
            });
        });
        $proxies = config('security.trusted_proxies', []);
        if (array_intersect($proxies, ['*', '**', 'REMOTE_ADDR'])) {
            throw new \InvalidArgumentException('TRUSTED_PROXIES must contain explicit proxy IPs/CIDRs.');
        }
        config(['trustedproxy.proxies' => $proxies]);
        \Illuminate\Http\Middleware\TrustProxies::at($proxies);
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }
    }

    private function registerIsolatedRateLimits(): void
    {
        $limits = [
            'session-status' => [120, 1, null],
            'session-activity' => [60, 1, null],
            'inventory-live' => [240, 1, null],
            'verification-link' => [30, 1, 'login'],
            'login-code-resend' => [3, 10, 'login.verify'],
            'password-email' => [3, 1, 'password.request'],
            'password-reset' => [5, 1, 'password.reset'],
            'staff-password-change' => [5, 1, 'staff.password.edit'],
            'inventory-removal' => [5, 1, 'admin.inventory'],
            'analytics-export' => [10, 1, 'admin.analytics'],
            'dead-stock-training' => [2, 10, 'admin.dead-stock'],
            'supplier-purge' => [5, 1, 'admin.suppliers'],
            'supplier-document-import' => [5, 10, 'admin.suppliers'],
        ];
        foreach ($limits as $name => [$attempts, $minutes, $destination]) {
            RateLimiter::for($name, function (Request $request) use ($attempts, $minutes, $destination) {
                $key = $request->user() ? 'user:'.$request->user()->id : 'ip:'.$request->ip();
                return Limit::perMinutes($minutes, $attempts)->by($key)
                    ->response(function (Request $request, array $headers) use ($destination) {
                        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
                        $message = "Please wait {$seconds} seconds before trying this action again. This request was not processed.";
                        if ($request->expectsJson() || $destination === null) {
                            return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
                        }
                        $target = $destination === 'password.reset' && ! $request->filled('token')
                            ? 'password.request' : $destination;
                        $parameters = $target === 'password.reset'
                            ? ['token' => (string) $request->input('token'), 'email' => (string) $request->input('email')]
                            : [];
                        // Only restore non-secret fields, never passwords or verification codes.
                        return redirect()->route($target, $parameters)
                            ->withInput($request->only('email'))
                            ->withErrors(['rate_limit' => $message])->withHeaders($headers);
                    });
            });
        }
    }
}
