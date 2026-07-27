<?php

namespace App\Http\Middleware;

use App\Services\Platform\TenantSubscriptionGate;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloqueia mutações HTTP (não-GET/HEAD/OPTIONS) quando a assinatura do tenant
 * está SUSPENDED ou CANCELED. Leitura de histórico permanece liberada.
 */
class EnsureTenantSubscriptionWritable
{
    public function __construct(
        private readonly TenantSubscriptionGate $gate,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        if (! $this->gate->allowsMutations()) {
            $status = $this->gate->subscriptionFor()?->status?->value ?? 'MISSING';

            return response()->json([
                'message' => "Escritório com assinatura {$status}: mutações bloqueadas.",
                'subscription_status' => $status,
            ], 403);
        }

        return $next($request);
    }
}
