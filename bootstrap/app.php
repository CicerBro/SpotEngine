<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureRegistrationIsOpen;
use App\Http\Middleware\RequireAdmin;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->appendToGroup('web', [
            EnsureRegistrationIsOpen::class,
        ]);

        $middleware->alias([
            'admin' => RequireAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (Throwable $e, Request $request): ?Response {
            if (! $request->is('api') && ! $request->is('api/*')) {
                return null;
            }

            if ($e instanceof HttpResponseException) {
                return null;
            }

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            $newznabCode = match (true) {
                $status === 401 || $status === 403 => 100,
                $status === 404 => 300,
                $status === 429 => 500,
                default => 900,
            };

            $message = app()->isProduction() && $status >= 500
                ? 'Internal server error'
                : ($e->getMessage() ?: 'Unknown error');
            $description = htmlspecialchars($message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n".'<error code="'.$newznabCode.'" description="'.$description.'"/>';

            return response($xml, $status, ['Content-Type' => 'text/xml; charset=utf-8']);
        });
    })->create();
