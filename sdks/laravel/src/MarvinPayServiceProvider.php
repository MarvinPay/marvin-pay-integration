<?php

declare(strict_types=1);

namespace MarvinPay\Laravel;

use Illuminate\Support\ServiceProvider;

class MarvinPayServiceProvider extends ServiceProvider
{
    /**
     * Merge config and bind the `marvinpay` singleton.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/marvinpay.php', 'marvinpay');

        $this->app->singleton('marvinpay', function ($app): MarvinPay {
            /** @var array<string,mixed> $config */
            $config = $app['config']->get('marvinpay', []);
            return new MarvinPay($config);
        });

        // Allow type-hinted resolution of the concrete service too.
        $this->app->alias('marvinpay', MarvinPay::class);
    }

    /**
     * Publish the config file. Example webhook routes live in
     * routes/marvinpay.php — register them from your app (see README) rather
     * than auto-loading, so you control the URL and middleware stack.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/marvinpay.php' => $this->app->configPath('marvinpay.php'),
            ], 'marvinpay-config');
        }
    }
}
