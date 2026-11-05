<?php

namespace App\Providers;

use App\Packages\PackageDiscovery;
use App\Packages\PackageRegistry;
use App\Runtime\LaravelHttpClient;
use Gll\Hub\Contracts\Http\HttpClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Once per process: discovery reads Composer's data and instantiates every package.
        $this->app->singleton(PackageRegistry::class, fn ($app): PackageRegistry => (new PackageDiscovery(
            (string) config('gllhub.packages.installed'),
        ))->populate(new PackageRegistry()));

        $this->app->bind(HttpClient::class, LaravelHttpClient::class);
    }

    public function boot(): void
    {
        // Per caller and per endpoint, so a noisy sender cannot starve the others.
        RateLimiter::for('webhooks', fn (Request $request): Limit => Limit::perMinute((int) config('gllhub.webhooks.per_minute'))
            ->by($request->ip().'|'.$request->route('package').'/'.$request->route('key')));

        RateLimiter::for('api-v2', fn (Request $request): Limit => Limit::perMinute((int) config('gllhub.api.per_minute'))
            ->by((string) $request->ip()));
    }
}
