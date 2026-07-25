<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\FiscalDocumentQuarantine;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Sefaz\FiscalDocumentQuarantineService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Inbox de quarentena — metadados sanitizados, sem XML/vault.
 */
class FiscalDocumentQuarantineController extends Controller
{
    public function index(
        Request $request,
        CurrentOffice $currentOffice,
        FiscalDocumentQuarantineService $quarantines,
    ): JsonResponse {
        $office = $currentOffice->office();
        $reason = $request->query('reason');
        $reason = is_string($reason) ? $reason : null;
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $items = $quarantines->listOpen($office->id, $reason, $limit);

        return response()->json([
            'data' => array_map(
                fn (FiscalDocumentQuarantine $q) => $q->toPublicArray(),
                $items
            ),
        ]);
    }

    public function resolve(
        Request $request,
        int $quarantine,
        CurrentOffice $currentOffice,
        TenantAuthorization $authorization,
        FiscalDocumentQuarantineService $quarantines,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $authorization->allows($actor, TenantPermission::ClientsManage)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        $data = $request->validate([
            'resolution_status' => ['required', 'string', 'in:RESOLVED,DISMISSED'],
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'resolution_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $model = FiscalDocumentQuarantine::query()
            ->where('office_id', $currentOffice->office()->id)
            ->whereKey($quarantine)
            ->first();

        if ($model === null) {
            abort(404);
        }

        try {
            $updated = $quarantines->resolve(
                item: $model,
                actor: $actor,
                resolutionStatus: $data['resolution_status'],
                code: $data['resolution_code'] ?? null,
                notes: $data['resolution_notes'] ?? null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $audit->record('fiscal_quarantine.resolve', 'SUCCESS', $updated, [
            'reason' => $updated->reason->value,
            'resolution_status' => $updated->resolution_status->value,
            'resolution_code' => $updated->resolution_code,
            // sem notes completas se contiverem payload — só comprimento
            'notes_len' => $updated->resolution_notes !== null ? mb_strlen($updated->resolution_notes) : 0,
            'access_key_prefix' => $updated->access_key !== null
                ? mb_substr($updated->access_key, 0, 8)
                : null,
        ]);

        $body = response()->json(['data' => $updated->toPublicArray()]);
        $content = $body->getContent() ?: '';
        // Defesa em profundidade: resposta pública nunca deve vazar vault
        if (str_contains($content, 'vault_object_id') || str_contains($content, 'BEGIN ')) {
            return response()->json(['message' => 'Resposta sanitizada bloqueada.'], 500);
        }

        return $body;
    }
}
