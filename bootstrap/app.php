<?php

use App\Exceptions\MarketplaceException;
use App\Http\Middleware\AuditAdminActions;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureSeller;
use App\Http\Middleware\RememberVisitor;
use App\Http\Middleware\SetLanguage;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['admin' => EnsureAdmin::class, 'seller' => EnsureSeller::class, 'audit' => AuditAdminActions::class]);
        $middleware->web(append: [RememberVisitor::class, SetLanguage::class]);

        // Payment webhooks come from the gateway, not a browser, so they carry no CSRF token.
        // The handler never trusts the body: it re-fetches the status from the gateway.
        $middleware->validateCsrfTokens(except: ['webhooks/*']);

        if ($proxies = env('TRUSTED_PROXIES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The mobile app always gets JSON errors.
        $exceptions->shouldRenderJsonWhen(fn (Request $request): bool => $request->is('api/*') || $request->expectsJson());

        // "Out of stock", "already shipped" and similar rules: the message is written for people.
        $exceptions->render(function (MarketplaceException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        });
    })->create();
