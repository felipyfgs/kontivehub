<?php

use App\Exceptions\ApiDomainException;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Middleware\EnsureTenantSubscriptionWritable;
use App\Http\Middleware\EnsureWorkRealMembership;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

return Application::configure(basePath: dirname(__DIR__))
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', EnsureTenantContext::class]],
    )
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->preventRequestForgery();

        // SPA: não há rota nomeada `login` no Laravel; API não autenticada deve responder 401.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'tenant' => EnsureTenantContext::class,
            'tenant.writable' => EnsureTenantSubscriptionWritable::class,
            'platform.admin' => EnsurePlatformAdmin::class,
            'password.recent' => EnsureRecentPasswordConfirmation::class,
            'work.real_membership' => EnsureWorkRealMembership::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ValidationException $error, Request $request) {
            $invalidCredentials = trans('auth.failed');
            $username = (string) config('fortify.username', 'email');
            if (
                ! $request->is('login')
                || ! $request->expectsJson()
                || ! in_array($invalidCredentials, $error->errors()[$username] ?? [], true)
            ) {
                return null;
            }

            return response()->json([
                'message' => $invalidCredentials,
                'code' => 'INVALID_CREDENTIALS',
                'errors' => $error->errors(),
            ], $error->status);
        });
        $exceptions->render(function (ApiDomainException $error, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return response()->json(array_merge($error->responseData(), [
                'message' => $error->safeMessage(),
                'code' => $error->stableCode(),
            ]), $error->httpStatus());
        });
    })->create();
