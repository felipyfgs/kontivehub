<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @deprecated TOTP/2FA de plataforma removido — passa adiante.
 */
class EnsurePlatformAdminTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
