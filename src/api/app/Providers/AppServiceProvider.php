<?php

namespace App\Providers;

use App\Support\ViewerTimezone;
use Illuminate\Support\Carbon;
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
        // A time as a page prints it: in the browser's timezone, not UTC (ViewerTimezone).
        // Not called `local()`: Carbon already has that method, and a macro can't replace one.
        Carbon::macro('forViewer', function (): Carbon {
            /** @var Carbon $this */
            return $this->copy()->setTimezone(ViewerTimezone::name());
        });
    }
}
