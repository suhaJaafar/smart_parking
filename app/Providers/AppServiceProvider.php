<?php

namespace App\Providers;

use App\Repositories\Contracts\LocationRepositoryInterface;
use App\Repositories\Contracts\ParkRepositoryInterface;
use App\Repositories\LocationRepository;
use App\Repositories\ParkRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(LocationRepositoryInterface::class, LocationRepository::class);
        $this->app->bind(ParkRepositoryInterface::class, ParkRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
