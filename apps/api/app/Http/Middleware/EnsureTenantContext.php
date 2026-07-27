<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenantContext
{
    public const CLIENT_TENANT_ID_SUPPLIED = 'client_tenant_id_supplied';

    public function __construct(private readonly CurrentTenant $currentTenant) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $tenant = $this->currentTenant->resolve($user);

        if ($tenant === null) {
            // PLATFORM_ADMIN sem Tenant válido: 409 estável para o cliente corrigir o padrão.
            if ($user instanceof User && $user->isPlatformAdmin()) {
                return response()->json([
                    'message' => 'Selecione um escritório ativo para continuar.',
                    'code' => CurrentTenant::CONTEXT_STATUS_REQUIRED,
                ], 409);
            }

            return response()->json([
                'message' => 'Usuário sem escritório ativo.',
                'code' => 'tenant_membership_required',
            ], 403);
        }

        // Strip any client-supplied tenant_id so domain code never trusts it
        // (inclui body JSON: getInputSource() usa json() para application/json).
        // Troca de tenant usa endpoint dedicado fora deste middleware.
        $this->stripClientTenantId($request);

        return $next($request);
    }

    private function stripClientTenantId(Request $request): void
    {
        $marker = false;

        $requestPayload = $request->request->all();
        $marker = $this->stripNestedTenantId($requestPayload) || $marker;
        $request->request->replace($requestPayload);

        $queryPayload = $request->query->all();
        $marker = $this->stripNestedTenantId($queryPayload) || $marker;
        $request->query->replace($queryPayload);

        if ($request->isJson() && $request->json() !== null) {
            $jsonPayload = $request->json()->all();
            $marker = $this->stripNestedTenantId($jsonPayload) || $marker;
            $request->json()->replace($jsonPayload);
        }

        if ($marker) {
            // Endpoints novos que exigem rejeição explícita podem consultar o marker
            // sem jamais ler/confiar no valor fornecido pelo cliente.
            $request->attributes->set(self::CLIENT_TENANT_ID_SUPPLIED, true);
        }
    }

    /**
     * Remove tenant_id recursivamente de arrays aninhados (payloads JSON complexos).
     * Retorna true se encontrou algum tenant_id.
     *
     * @param  array<string, mixed>  $array
     */
    private function stripNestedTenantId(array &$array): bool
    {
        $found = false;
        foreach ($array as $key => &$value) {
            if ($key === 'tenant_id') {
                unset($array[$key]);
                $found = true;

                continue;
            }

            if (is_array($value)) {
                $found = $this->stripNestedTenantId($value) || $found;
            }
        }
        unset($value);

        return $found;
    }
}
