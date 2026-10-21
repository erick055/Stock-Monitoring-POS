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
        RateLimiter::for('email-verification-status', fn (Request $request) =>
            Limit::perMinute(30)->by((string) $request->user()->id));
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
}
