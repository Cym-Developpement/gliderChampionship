<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->redirectGuestsTo('/admin/login');

        // GitHub ne peut pas fournir de jeton CSRF ; la requête est authentifiée
        // par la signature HMAC de l'en-tête X-Hub-Signature-256.
        $middleware->validateCsrfTokens(except: [
            'webhook/github',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
