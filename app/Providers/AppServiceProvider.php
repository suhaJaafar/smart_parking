<?php

namespace App\Providers;

use App\Bots\Contracts\BotNotifier;
use App\Bots\Support\UserNotifier;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Bot subsystem — channel-agnostic notifier fans out across every
        // channel a user is enrolled in (WhatsApp, Telegram, …).
        $this->app->bind(BotNotifier::class, UserNotifier::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
