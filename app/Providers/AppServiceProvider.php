<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use App\Pricing\CostCalculator;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CostCalculator::class, fn () => new CostCalculator(
            config('services.pixelforge.markup_percent'),
        ));

        $this->app->singleton(PixelforgeClient::class, fn () => new PixelforgeClient(
            config('services.pixelforge.base_url'),
            config('services.pixelforge.api_key'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);
    }
}
