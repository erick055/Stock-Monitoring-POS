<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
