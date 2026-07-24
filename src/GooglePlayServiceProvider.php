<?php

declare(strict_types=1);

namespace Breakpoint\GooglePlay;

use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Support\ServiceProvider;
use Psr\Log\LoggerInterface;

/**
 * Bindings and config only — the app owns the RTDN route so this can stay deferred.
 */
class GooglePlayServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/google-play-billing.php', 'google-play-billing');

        $this->app->singleton(GooglePlayManager::class, fn ($app): GooglePlayManager => new GooglePlayManager(
            $app['config']->get('google-play-billing'),
            $app->bound(LoggerInterface::class) ? $app->make(LoggerInterface::class) : null,
        ));

        $this->app->bind(Validator::class, fn ($app): Validator => $app->make(GooglePlayManager::class)->validator());
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/google-play-billing.php' => config_path('google-play-billing.php'),
            ], 'google-play-billing-config');
        }
    }

    /**
     * @return list<string>
     */
    public function provides(): array
    {
        return [GooglePlayManager::class, Validator::class];
    }
}
