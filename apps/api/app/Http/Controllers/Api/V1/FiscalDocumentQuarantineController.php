<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Documents\ResolveFiscalDocumentQuarantineRequest;
use App\Models\FiscalDocumentQuarantine;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\FiscalDocumentQuarantineService;
use App\Support\CurrentTenant;
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
        CurrentTenant $currentTenant,
        FiscalDocumentQuarantineService $quarantines,
    ): JsonResponse {
        $tenant = $currentTenant->tenant();
        $reason = $request->query('reason');
        $reason = is_string($reason) ? $reason : null;
        $limit = min(100, max(1, (int) $request->query('limit', 50)));

        $items = $quarantines->listOpen($tenant->id, $reason, $limit);

        return response()->json([
            'data' => array_map(
                fn (FiscalDocumentQuarantine $q) => $q->toPublicArray(),
                $items
            ),
        ]);
    }

    public function resolve(
        ResolveFiscalDocumentQuarantineRequest $request,
        int $quarantine,
        CurrentTenant $currentTenant,
        FiscalDocumentQuarantineService $quarantines,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->actor();

        $model = FiscalDocumentQuarantine::query()
            ->where('tenant_id', $currentTenant->tenant()->id)
            ->whereKey($quarantine)
            ->first();

        if ($model === null) {
            abort(404);
        }

        try {
            $updated = $quarantines->resolve(
                item: $model,
                actor: $actor,
                resolutionStatus: $request->resolutionStatus(),
                code: $request->resolutionCode(),
                notes: $request->resolutionNotes(),
            );
        } catch (RuntimeException $e) {
            $text = $e->getMessage();

            return response()->json(['message' => $text], 422);
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
