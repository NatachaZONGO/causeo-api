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
        // Lien court des statuts WhatsApp : hors du groupe « web », sans session ni cookie.
        then: function (): void {
            \Illuminate\Support\Facades\Route::get('c/{code}', \App\Http\Controllers\StatusShortLinkController::class)
                ->where('code', '[A-Za-z]{3,12}[0-9]{1,9}');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\PreventApiCaching::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'whatsapp.express' => \App\Http\Middleware\EnsureWhatsAppExpressEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
