<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantRole;
use App\Http\Controllers\Controller;
use App\Models\TenantIntegrationToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Import\OutboundXmlIngestionService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * EMITTER_PUSH: entrega autenticada de CT-e pelo emissor/ERP.
 * Token exibido uma vez; tenant_id derivado do principal, nunca do payload.
 */
class CteEmitterPushController extends Controller
{
    /**
     * ADMIN + senha recente: emite token (plaintext uma vez).
     */
    public function issueToken(Request $request, CurrentTenant $currentTenant, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->denyUnlessAdminWithRecentPassword($request, $currentTenant, 'emitir tokens de integração')) {
            return $denied;
        }
        if (! config('sefaz.cte_emitter_push.enabled', false)) {
            return response()->json(['message' => 'Entrega EMITTER_PUSH desabilitada.'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $tenant = $currentTenant->tenant();
        $plain = 'cte_'.Str::random(48);
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);

        $token = TenantIntegrationToken::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $validated['name'],
            'token_prefix' => $prefix,
            'token_hash' => $hash,
            'scope' => 'cte:ingest',
            'status' => 'ACTIVE',
            'expires_at' => isset($validated['expires_in_days'])
                ? now()->addDays((int) $validated['expires_in_days'])
                : now()->addYear(),
            'created_by' => $request->user()?->id,
        ]);

        $audit->record('tenant.integration_token.issued', 'SUCCESS', $token, [
            'token_prefix' => $prefix,
            'scope' => 'cte:ingest',
            // sem plaintext
        ]);

        return response()->json([
            'data' => array_merge($token->toPublicArray(), [
                'token' => $plain, // única vez
                'warning' => 'Guarde o token agora; ele não poderá ser recuperado.',
            ]),
        ], 201);
    }

    /**
     * Admin com senha recente revoga token sem recuperação.
     * A autorização é revalidada no controller.
     */
    public function revokeToken(Request $request, CurrentTenant $currentTenant, TenantIntegrationToken $token, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->denyUnlessAdminWithRecentPassword($request, $currentTenant, 'revogar tokens')) {
            return $denied;
        }
        if ((int) $token->tenant_id !== (int) $currentTenant->tenant()->id) {
            return response()->json(['message' => 'Token não encontrado.'], 404);
        }

        $token->status = 'REVOKED';
        $token->revoked_at = now();
        $token->revoked_by = $request->user()?->id;
        $token->save();

        $audit->record('tenant.integration_token.revoked', 'SUCCESS', $token, [
            'token_prefix' => $token->token_prefix,
        ]);

        return response()->json(['data' => $token->toPublicArray()]);
    }

    public function listTokens(CurrentTenant $currentTenant): JsonResponse
    {
        if ($currentTenant->role() === null) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $items = TenantIntegrationToken::query()
            ->where('tenant_id', $currentTenant->tenant()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (TenantIntegrationToken $t) => $t->toPublicArray());

        return response()->json(['data' => $items]);
    }

    /**
     * Defesa em profundidade: ADMIN + senha recente.
     * Não depende do grupo de rotas — seguro se issue/revoke forem reutilizados.
     */
    private function denyUnlessAdminWithRecentPassword(Request $request, CurrentTenant $currentTenant, string $actionLabel): ?JsonResponse
    {
        if ($currentTenant->role() !== TenantRole::TenantAdmin) {
            return response()->json([
                'message' => "Apenas ADMIN pode {$actionLabel}.",
            ], 403);
        }

        $user = $request->user();
        $gate = app(RecentPasswordConfirmationGate::class);
        if (! $user instanceof User || ! $gate->isRecentlyConfirmed($user, $request)) {
            return response()->json([
                'message' => 'Reconfirme sua senha para acessar funções administrativas.',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        return null;
    }

    /**
     * Push público autenticado por Bearer token (não sessão Sanctum).
     * Rate limit via middleware na rota.
     */
    public function push(Request $request, OutboundXmlIngestionService $ingestion): JsonResponse
    {
        if (! config('sefaz.cte_emitter_push.enabled', false)) {
            return response()->json(['message' => 'Serviço indisponível.'], 503);
        }

        $auth = (string) $request->bearerToken();
        if ($auth === '') {
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $hash = hash('sha256', $auth);
        $token = TenantIntegrationToken::query()->where('token_hash', $hash)->first();
        if ($token === null || ! $token->isUsable() || $token->scope !== 'cte:ingest') {
            // Resposta genérica — sem revelar existência de tenant/token
            return response()->json(['message' => 'Não autenticado.'], 401);
        }

        $maxBytes = (int) config('sefaz.cte_emitter_push.max_payload_bytes', 5_242_880);
        $xml = (string) $request->getContent();
        if ($xml === '' && $request->hasFile('file')) {
            $xml = (string) file_get_contents($request->file('file')->getRealPath() ?: '');
        }
        if ($xml === '' && is_string($request->input('xml'))) {
            $xml = (string) $request->input('xml');
        }
        if ($xml === '' || strlen($xml) > $maxBytes) {
            return response()->json(['message' => 'Payload inválido ou excessivo.'], 422);
        }

        // Apenas ingestão de guarda — nunca emissão/cancelamento SEFAZ
        $report = $ingestion->ingestXmlBytes(
            (int) $token->tenant_id,
            null,
            $xml,
            'emitter-push.xml',
        );

        $token->last_used_at = now();
        $token->save();

        $status = match ($report['status'] ?? 'error') {
            'imported' => 201,
            'duplicate' => 200,
            default => 422,
        };

        return response()->json([
            'data' => [
                'status' => $report['status'],
                'access_key' => $report['access_key'] ?? null,
                'sha256' => $report['sha256'] ?? null,
                'kind' => $report['kind'] ?? null,
                'message' => $report['message'] ?? null,
                'result_code' => $report['result_code'] ?? null,
                // Sem vault, XML, PFX
            ],
        ], $status);
    }
}
