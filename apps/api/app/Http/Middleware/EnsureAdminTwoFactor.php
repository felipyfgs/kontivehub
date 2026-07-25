<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated TOTP/2FA removido — passa adiante. Use EnsureRecentPasswordConfirmation.
 * Mantido como no-op para aliases e testes legados até remoção física.
 */
class EnsureAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
