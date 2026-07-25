<?php

use App\Http\Middleware\EnsureAdminTwoFactor;
use App\Http\Middleware\EnsureOfficeContext;
use App\Http\Middleware\EnsureOfficeSubscriptionWritable;
use App\Http\Middleware\EnsurePlatformAdmin;
use App\Http\Middleware\EnsurePlatformAdminTwoFactor;
use App\Http\Middleware\EnsureRecentPasswordConfirmation;
use App\Http\Middleware\EnsureWorkRealMembership;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum', EnsureOfficeContext::class]],
    )
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        // SPA: não há rota nomeada `login` no Laravel; API não autenticada deve responder 401.
        $middleware->redirectGuestsTo(fn (Request $request) => null);

        $middleware->alias([
            'office' => EnsureOfficeContext::class,
            'office.writable' => EnsureOfficeSubscriptionWritable::class,
            'admin.2fa' => EnsureAdminTwoFactor::class, // no-op legado
            'platform.admin' => EnsurePlatformAdmin::class,
            'platform.2fa' => EnsurePlatformAdminTwoFactor::class, // no-op legado
            'privileged.password' => EnsureRecentPasswordConfirmation::class,
            'password.recent' => EnsureRecentPasswordConfirmation::class,
            'work.real_membership' => EnsureWorkRealMembership::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReportWhen(fn (Throwable $error): bool => $error instanceof DomainException
            && in_array($error->getMessage(), [
                'COMMUNICATION_DISABLED',
                'OFFICE_COMMUNICATION_DISABLED',
                'INBOX_COMMUNICATION_DISABLED',
                'INBOX_NOT_CONNECTED',
                'ASSISTANT_DISABLED',
                'ASSISTANT_TOOL_UNKNOWN',
            ], true));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (DomainException $error, Request $request) {
            $communicationCodes = [
                'COMMUNICATION_DISABLED',
                'OFFICE_COMMUNICATION_DISABLED',
                'INBOX_COMMUNICATION_DISABLED',
                'INBOX_NOT_CONNECTED',
            ];
            if ($request->is('api/*') && $error->getMessage() === 'ASSISTANT_DISABLED') {
                return response()->json([
                    'message' => 'Assistente indisponível.',
                    'code' => 'ASSISTANT_DISABLED',
                ], 503);
            }
            if ($request->is('api/*') && $error->getMessage() === 'ASSISTANT_TOOL_UNKNOWN') {
                return response()->json([
                    'message' => 'Tool do assistente não permitida.',
                    'code' => 'ASSISTANT_TOOL_UNKNOWN',
                ], 422);
            }
            if (! $request->is('api/*') || ! in_array($error->getMessage(), $communicationCodes, true)) {
                return null;
            }

            return response()->json([
                'message' => 'Canal de comunicação indisponível.',
                'code' => $error->getMessage(),
            ], $error->getMessage() === 'INBOX_NOT_CONNECTED' ? 409 : 503);
        });
    })->create();
