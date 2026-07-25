<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentOffice;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOfficeContext
{
    public const CLIENT_OFFICE_ID_SUPPLIED = 'client_office_id_supplied';

    public function __construct(private readonly CurrentOffice $currentOffice) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $office = $this->currentOffice->resolve($user);

        if ($office === null) {
            // PLATFORM_ADMIN sem Office válido: 409 estável para o cliente corrigir o padrão.
            if ($user instanceof User && $user->isPlatformAdmin()) {
                return response()->json([
                    'message' => 'Selecione um escritório ativo para continuar.',
                    'code' => CurrentOffice::CONTEXT_STATUS_REQUIRED,
                ], 409);
            }

            return response()->json([
                'message' => 'Usuário sem escritório ativo.',
                'code' => 'office_membership_required',
            ], 403);
        }

        // Strip any client-supplied office_id so domain code never trusts it
        // (inclui body JSON: getInputSource() usa json() para application/json).
        // Troca de tenant usa endpoint dedicado fora deste middleware.
        $this->stripClientOfficeId($request);

        return $next($request);
    }

    private function stripClientOfficeId(Request $request): void
    {
        $marker = false;

        $requestPayload = $request->request->all();
        $marker = $this->stripNestedOfficeId($requestPayload) || $marker;
        $request->request->replace($requestPayload);

        $queryPayload = $request->query->all();
        $marker = $this->stripNestedOfficeId($queryPayload) || $marker;
        $request->query->replace($queryPayload);

        if ($request->isJson() && $request->json() !== null) {
            $jsonPayload = $request->json()->all();
            $marker = $this->stripNestedOfficeId($jsonPayload) || $marker;
            $request->json()->replace($jsonPayload);
        }

        if ($marker) {
            // Endpoints novos que exigem rejeição explícita podem consultar o marker
            // sem jamais ler/confiar no valor fornecido pelo cliente.
            $request->attributes->set(self::CLIENT_OFFICE_ID_SUPPLIED, true);
        }
    }

    /**
     * Remove office_id recursivamente de arrays aninhados (payloads JSON complexos).
     * Retorna true se encontrou algum office_id.
     *
     * @param  array<string, mixed>  $array
     */
    private function stripNestedOfficeId(array &$array): bool
    {
        $found = false;
        foreach ($array as $key => &$value) {
            if ($key === 'office_id') {
                unset($array[$key]);
                $found = true;

                continue;
            }

            if (is_array($value)) {
                $found = $this->stripNestedOfficeId($value) || $found;
            }
        }
        unset($value);

        return $found;
    }
}
