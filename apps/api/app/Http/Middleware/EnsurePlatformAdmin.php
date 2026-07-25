<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Autorização global PLATFORM_ADMIN.
 * Não resolve nem exige CurrentOffice — admin da plataforma ≠ acesso fiscal.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->is_active || ! $user->isPlatformAdmin()) {
            return response()->json([
                'message' => 'Ação restrita a administradores da plataforma.',
            ], 403);
        }

        return $next($request);
    }
}
