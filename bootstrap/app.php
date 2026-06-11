<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->preventRequestForgery(except: [
        'webhook',
        'telegram/webhook',
    ]);
        $middleware->alias([
            'role'             => \App\Http\Middleware\CheckRole::class,
            'whatsapp.signed'  => \App\Bots\Channels\WhatsApp\VerifyWhatsAppSignature::class,
            'telegram.signed'  => \App\Bots\Channels\Telegram\VerifyTelegramSecret::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
