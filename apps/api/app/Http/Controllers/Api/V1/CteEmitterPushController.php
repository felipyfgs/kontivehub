<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\OfficeIntegrationToken;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Import\OutboundXmlIngestionService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * EMITTER_PUSH: entrega autenticada de CT-e pelo emissor/ERP.
 * Token exibido uma vez; office_id derivado do principal, nunca do payload.
 */
class CteEmitterPushController extends Controller
{
    /**
     * ADMIN+2FA: emite token (plaintext uma vez).
     * 2FA reassert no controller (não confiar só no middleware da rota).
     */
    public function issueToken(Request $request, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->denyUnlessAdminWithTwoFactor($request, $currentOffice, 'emitir tokens de integração')) {
            return $denied;
        }
        if (! config('sefaz.cte_emitter_push.enabled', false)) {
            return response()->json(['message' => 'Entrega EMITTER_PUSH desabilitada.'], 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'expires_in_days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);

        $office = $currentOffice->office();
        $plain = 'cte_'.Str::random(48);
        $hash = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);

        $token = OfficeIntegrationToken::query()->create([
            'office_id' => $office->id,
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

        $audit->record('office.integration_token.issued', 'SUCCESS', $token, [
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
     * ADMIN+2FA: revoga token (sem recuperação).
     * 2FA reassert no controller (não confiar só no middleware da rota).
     */
    public function revokeToken(Request $request, CurrentOffice $currentOffice, OfficeIntegrationToken $token, AuditLogger $audit): JsonResponse
    {
        if ($denied = $this->denyUnlessAdminWithTwoFactor($request, $currentOffice, 'revogar tokens')) {
            return $denied;
        }
        if ((int) $token->office_id !== (int) $currentOffice->office()->id) {
            return response()->json(['message' => 'Token não encontrado.'], 404);
        }

        $token->status = 'REVOKED';
        $token->revoked_at = now();
        $token->revoked_by = $request->user()?->id;
        $token->save();

        $audit->record('office.integration_token.revoked', 'SUCCESS', $token, [
            'token_prefix' => $token->token_prefix,
        ]);

        return response()->json(['data' => $token->toPublicArray()]);
    }

    public function listTokens(CurrentOffice $currentOffice): JsonResponse
    {
        if ($currentOffice->role() === null || ! in_array($currentOffice->role(), [OfficeRole::Admin, OfficeRole::Operator, OfficeRole::Viewer], true)) {
            return response()->json(['message' => 'Não autorizado.'], 403);
        }

        $items = OfficeIntegrationToken::query()
            ->where('office_id', $currentOffice->office()->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (OfficeIntegrationToken $t) => $t->toPublicArray());

        return response()->json(['data' => $items]);
    }

    /**
     * Defesa em profundidade: ADMIN + senha recente.
     * Não depende do grupo de rotas — seguro se issue/revoke forem reutilizados.
     */
    private function denyUnlessAdminWithTwoFactor(Request $request, CurrentOffice $currentOffice, string $actionLabel): ?JsonResponse
    {
        if ($currentOffice->role() !== OfficeRole::Admin) {
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
        $token = OfficeIntegrationToken::query()->where('token_hash', $hash)->first();
        if ($token === null || ! $token->isUsable() || $token->scope !== 'cte:ingest') {
            // Resposta genérica — sem revelar existência de office/token
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
            (int) $token->office_id,
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
