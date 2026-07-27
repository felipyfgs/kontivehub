<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate Work: mutações e exportações exigem TenantMembership real no Tenant corrente
 * ou PLATFORM_ADMIN em contexto privilegiado (paridade de superfície tenant).
 * Leitura privilegiada não passa por este middleware.
 *
 * @see config/work_route_matrix.php
 */
class EnsureWorkRealMembership
{
    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            return $next($request);
        }

        $this->currentTenant->resolve($user);

        if ($this->currentTenant->hasRealMembership()) {
            return $next($request);
        }

        // PLATFORM_ADMIN com tenant selecionado: mesma superfície mutante do Tenant ADMIN.
        if ($this->currentTenant->isPlatformPrivileged() && $user->isPlatformAdmin()) {
            return $next($request);
        }

        // Sem membership e sem contexto privilegiado: já deveria ter falhado em EnsureTenantContext.
        return response()->json([
            'message' => 'Membership de escritório necessária para esta operação.',
            'code' => 'work_real_membership_required',
        ], 403);
    }
}
