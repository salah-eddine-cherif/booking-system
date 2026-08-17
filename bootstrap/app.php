<?php

use App\Exceptions\SlotUnavailableException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A taken slot is a conflict, not a validation error: the request was
        // well-formed, the world simply moved. 409 lets a client tell "your input
        // is wrong" apart from "try a different time".
        $exceptions->render(function (SlotUnavailableException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason,
            ], 409);
        });

        $exceptions->render(function (DomainException $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json(['message' => $e->getMessage()], 422);
        });
    })->create();
