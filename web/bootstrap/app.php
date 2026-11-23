<?php

use App\Domain\ImmutableRecordException;
use App\Http\Middleware\ActiveAccountingYear;
use App\Http\Middleware\EnsureCompanyMember;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [HandleInertiaRequests::class]);
        $middleware->alias(['company.member' => EnsureCompanyMember::class, 'active.year' => ActiveAccountingYear::class, 'module' => EnsureModuleEnabled::class]);
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        // Tentativo di modifica di record immutabili: messaggio comprensibile invece di un errore del server.
        $exceptions->render(function (ImmutableRecordException $e, Request $request) {
            return back()->withErrors(['profile' => $e->getMessage()]);
        });
    })->create();
