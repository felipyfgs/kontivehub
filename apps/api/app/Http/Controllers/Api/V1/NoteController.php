<?php

namespace App\Http\Controllers\Api\V1;

use App\Contracts\SecureObjectStore;
use App\Enums\DocumentArtifactQuality;
use App\Enums\NfeManifestationType;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\CteDocument;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\NfeDocument;
use App\Models\NfseEvent;
use App\Models\NfseNote;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Documents\DocumentCatalogService;
use App\Services\Sefaz\NfeManifestationService;
use App\Services\Sefaz\NfeXmlUnlockService;
use App\Services\Vault\DocumentVaultReader;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class NoteController extends Controller
{
    public function index(
        Request $request,
        CurrentOffice $currentOffice,
        DocumentCatalogService $catalog,
    ): JsonResponse {
        return response()->json($catalog->index($request, $currentOffice));
    }

    /**
     * Contagens de triagem no escopo dos filtros (chips clicáveis).
     * Facetas de status/competência ignoram o próprio campo para o operador ver o recorte.
     */
    public function insights(
        Request $request,
        CurrentOffice $currentOffice,
        DocumentCatalogService $catalog,
    ): JsonResponse {
        return response()->json($catalog->insights($request, $currentOffice));
    }

    /**
     * Agregação por cliente do escritório (aba "Por empresa").
     * Conta notas distintas com interesse em estabelecimento do cliente, no escopo dos filtros.
     */
    public function byClient(
        Request $request,
        CurrentOffice $currentOffice,
        DocumentCatalogService $catalog,
    ): JsonResponse {
        return response()->json($catalog->byClient($request, $currentOffice));
    }

    public function show(string $accessKey, DocumentCatalogService $catalog): JsonResponse
    {
        $note = NfseNote::query()->where('access_key', $accessKey)->with('document')->first();
        if ($note !== null) {
            $events = NfseEvent::query()
                ->where('access_key', $accessKey)
                ->orderBy('event_at')
                ->orderBy('id')
                ->get();

            $notePayload = $catalog->serializeNoteListItem($note);
            $notePayload['dfe_document_id'] = $note->dfe_document_id;
            $notePayload['office_id'] = $note->office_id;
            $notePayload['has_full_xml'] = true;
            $notePayload['xml_completeness'] = 'FULL';

            return response()->json([
                'data' => [
                    'note' => $notePayload,
                    'events' => $events,
                    'document' => [
                        'id' => $note->document?->id,
                        'sha256' => $note->document?->sha256,
                        'schema_version' => $note->document?->schema_version,
                        'parse_status' => $note->document?->parse_status,
                        'parse_alert' => $note->document?->parse_alert,
                        'byte_size' => $note->document?->byte_size,
                        'document_type' => $note->document?->document_type,
                    ],
                ],
            ]);
        }

        // Prefer full (is_summary=false) sobre resumo para entrega de XML.
        $nfe = NfeDocument::query()
            ->where('access_key', $accessKey)
            ->orderBy('is_summary') // false first
            ->with(['document.interests', 'document.acquisitions'])
            ->first();

        if ($nfe !== null) {
            $hasFull = NfeDocument::query()
                ->where('access_key', $accessKey)
                ->where('is_summary', false)
                ->exists();

            $payload = $catalog->serializeNfeListItem($nfe);
            $payload['dfe_document_id'] = $nfe->dfe_document_id;
            $payload['office_id'] = $nfe->office_id;
            $payload['has_full_xml'] = $hasFull;
            $payload['xml_completeness'] = $hasFull ? 'FULL' : 'SUMMARY_ONLY';

            $acquisitions = ($nfe->document?->acquisitions ?? collect())->map(fn ($a) => [
                'source' => is_object($a->source) ? $a->source->value : $a->source,
                'channel' => is_object($a->channel) ? $a->channel->value : $a->channel,
                'sha256' => $a->sha256,
                'is_canonical' => (bool) $a->is_canonical,
                'nsu' => $a->metadata['nsu'] ?? null,
                'created_at' => $a->created_at?->toIso8601String(),
            ])->values()->all();

            return response()->json([
                'data' => [
                    'note' => $payload,
                    'events' => [],
                    'interests' => $payload['interests'] ?? [],
                    'acquisitions' => $acquisitions,
                    'document' => [
                        'id' => $nfe->document?->id,
                        'sha256' => $nfe->document?->sha256,
                        'schema_version' => $nfe->document?->schema_version,
                        'parse_status' => $nfe->document?->parse_status,
                        'parse_alert' => $nfe->document?->parse_alert,
                        'byte_size' => $nfe->document?->byte_size,
                        'document_type' => $nfe->document?->document_type,
                    ],
                ],
            ]);
        }

        $cte = CteDocument::query()
            ->where('access_key', $accessKey)
            ->orderBy('is_summary')
            ->with('document')
            ->first();

        if ($cte !== null) {
            $hasFull = CteDocument::query()
                ->where('access_key', $accessKey)
                ->where('is_summary', false)
                ->exists();

            $payload = $catalog->serializeCteListItem($cte);
            $payload['dfe_document_id'] = $cte->dfe_document_id;
            $payload['office_id'] = $cte->office_id;
            $payload['has_full_xml'] = $hasFull;
            $payload['xml_completeness'] = $hasFull ? 'FULL' : 'SUMMARY_ONLY';

            return response()->json([
                'data' => [
                    'note' => $payload,
                    'events' => [],
                    'document' => [
                        'id' => $cte->document?->id,
                        'sha256' => $cte->document?->sha256,
                        'schema_version' => $cte->document?->schema_version,
                        'parse_status' => $cte->document?->parse_status,
                        'parse_alert' => $cte->document?->parse_alert,
                        'byte_size' => $cte->document?->byte_size,
                        'document_type' => $cte->document?->document_type,
                    ],
                ],
            ]);
        }

        abort(404, 'Documento não encontrado.');
    }

    /**
     * Solicita desbloqueio de XML completo (ciência unlock) para NF-e só-resumo.
     * VIEWER: 403. Full já presente: no-op 200.
     */
    public function unlockXml(
        string $accessKey,
        Request $request,
        CurrentOffice $currentOffice,
        TenantAuthorization $authorization,
        NfeXmlUnlockService $unlock,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $authorization->allows($actor, TenantPermission::FiscalNfeManifest)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        $result = $unlock->unlock($accessKey, $currentOffice->office()->id);

        $audit->record(
            'nfe.xml_unlock',
            in_array($result['status'], ['already_full', 'accepted'], true) ? 'SUCCESS' : 'INFO',
            null,
            [
                'access_key' => $accessKey,
                'status' => $result['status'],
                'c_stat' => $result['c_stat'] ?? null,
            ]
        );

        $http = match ($result['status']) {
            'already_full', 'accepted' => 200,
            'not_found' => 404,
            'flag_off', 'pending_integration', 'no_credential', 'rejected_local',
            'rejected_sefaz', 'validation_error', 'error' => 422,
            default => 422,
        };

        return response()->json(['data' => $result], $http);
    }

    /**
     * Manifestação do destinatário (ciência / conclusivas). VIEWER: 403.
     */
    public function manifest(
        string $accessKey,
        Request $request,
        CurrentOffice $currentOffice,
        TenantAuthorization $authorization,
        NfeManifestationService $manifestation,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $authorization->allows($actor, TenantPermission::FiscalNfeManifest)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        $validated = $request->validate([
            'type' => ['required', 'string'],
            'justification' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'in:UNLOCK_XML,FISCAL'],
        ]);

        $type = NfeManifestationType::tryFromInput((string) $validated['type']);
        if ($type === null) {
            return response()->json([
                'message' => 'Tipo de manifestação inválido.',
                'errors' => ['type' => ['Use CIENCIA, CONFIRMACAO, DESCONHECIMENTO ou NAO_REALIZADA.']],
            ], 422);
        }

        $result = $manifestation->manifest(
            $accessKey,
            $currentOffice->office()->id,
            $type,
            $validated['justification'] ?? null,
            (string) ($validated['purpose'] ?? 'UNLOCK_XML'),
        );

        $audit->record(
            'nfe.manifestation',
            $result['status'] === 'accepted' ? 'SUCCESS' : 'INFO',
            null,
            [
                'access_key' => $accessKey,
                'type' => $type->value,
                'tp_evento' => $type->tpEvento(),
                'purpose' => $validated['purpose'] ?? 'UNLOCK_XML',
                'status' => $result['status'],
                'c_stat' => $result['c_stat'] ?? null,
                // Nunca auditar justificativa completa se contiver dados sensíveis demais — só tamanho
                'justification_len' => isset($validated['justification'])
                    ? mb_strlen((string) $validated['justification'])
                    : 0,
            ]
        );

        $http = match ($result['status']) {
            'already_full', 'accepted' => 200,
            'not_found' => 404,
            default => 422,
        };

        return response()->json(['data' => $result], $http);
    }

    public function downloadXml(
        string $accessKey,
        SecureObjectStore $store,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): StreamedResponse {
        try {
            return $this->streamCanonicalXml($accessKey, $store, $audit);
        } catch (\RuntimeException $e) {
            report($e);
            abort(422, 'XML indisponível no cofre (não foi possível abrir o objeto).');
        }
    }

    private function streamCanonicalXml(
        string $accessKey,
        SecureObjectStore $store,
        AuditLogger $audit,
    ): StreamedResponse {
        $note = NfseNote::query()->where('access_key', $accessKey)->with('document')->first();
        if ($note !== null) {
            $doc = $note->document;
            if ($doc === null) {
                abort(404, 'XML canônico indisponível.');
            }
            $bytes = DocumentVaultReader::get(
                $store,
                (string) $doc->vault_object_id,
                (int) $doc->office_id,
                (string) $doc->sha256,
            );
            $audit->record('xml.download', 'SUCCESS', $note, [
                'access_key' => $accessKey,
                'sha256' => $doc->sha256,
                'byte_size' => $doc->byte_size,
                'xml_kind' => 'NFSE',
            ]);

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $accessKey.'.xml', [
                'Content-Type' => 'application/xml',
            ]);
        }

        // Prefer full over summary; always bytes canônicos do dfe (nunca quarentena/spool).
        $nfe = NfeDocument::query()
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->with('document')
            ->first();
        if ($nfe === null) {
            $nfe = NfeDocument::query()
                ->where('access_key', $accessKey)
                ->orderBy('is_summary')
                ->with('document')
                ->first();
        }

        if ($nfe !== null) {
            $doc = $nfe->document;
            if ($doc === null || $doc->parse_status === 'QUARANTINE') {
                abort(404, 'XML canônico indisponível.');
            }
            $bytes = DocumentVaultReader::get(
                $store,
                (string) $doc->vault_object_id,
                (int) $doc->office_id,
                (string) $doc->sha256,
            );

            $audit->record('xml.download', 'SUCCESS', $nfe, [
                'access_key' => $accessKey,
                'sha256' => $doc->sha256,
                'byte_size' => $doc->byte_size,
                'xml_kind' => $nfe->is_summary ? 'NFE_SUMMARY' : 'NFE_FULL',
                'is_summary' => $nfe->is_summary,
            ]);

            $suffix = $nfe->is_summary ? '-resumo' : '';

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $accessKey.$suffix.'.xml', [
                'Content-Type' => 'application/xml',
                'X-Xml-Completeness' => $nfe->is_summary ? 'SUMMARY' : 'FULL',
            ]);
        }

        $cte = CteDocument::query()
            ->where('access_key', $accessKey)
            ->orderBy('is_summary')
            ->with('document')
            ->first();

        if ($cte !== null) {
            // Preferência de download: ORIGINAL > AUTXML_ORIGINAL > AUTXML_REDACTED (sem reconstrução).
            $preferredAcq = DocumentAcquisition::query()
                ->where('office_id', $cte->office_id)
                ->where('access_key', $accessKey)
                ->where('is_canonical', true)
                ->orderByRaw("CASE artifact_quality
                    WHEN 'ORIGINAL' THEN 3
                    WHEN 'AUTXML_ORIGINAL' THEN 2
                    WHEN 'AUTXML_REDACTED' THEN 1
                    ELSE 0 END DESC")
                ->orderByDesc('id')
                ->first();

            $doc = null;
            if ($preferredAcq !== null) {
                $doc = DfeDocument::query()
                    ->where('office_id', $cte->office_id)
                    ->whereKey($preferredAcq->dfe_document_id)
                    ->first();
            }
            $doc ??= $cte->document;
            if ($doc === null) {
                abort(404, 'XML canônico indisponível.');
            }
            $bytes = DocumentVaultReader::get(
                $store,
                (string) $doc->vault_object_id,
                (int) $doc->office_id,
                (string) $doc->sha256,
            );

            $quality = $preferredAcq?->artifact_quality;
            $qualityValue = $quality instanceof DocumentArtifactQuality
                ? $quality->value
                : (string) ($quality ?? 'UNKNOWN');

            $audit->record('xml.download', 'SUCCESS', $cte, [
                'access_key' => $accessKey,
                'sha256' => $doc->sha256,
                'byte_size' => $doc->byte_size,
                'xml_kind' => $cte->is_summary ? 'CTE_SUMMARY' : 'CTE_FULL',
                'is_summary' => $cte->is_summary,
                'artifact_quality' => $qualityValue,
            ]);

            $suffix = $cte->is_summary ? '-resumo' : '';

            return response()->streamDownload(function () use ($bytes): void {
                echo $bytes;
            }, $accessKey.$suffix.'.xml', [
                'Content-Type' => 'application/xml',
                'X-Xml-Completeness' => $cte->is_summary ? 'SUMMARY' : 'FULL',
                'X-Artifact-Quality' => $qualityValue,
            ]);
        }

        abort(404, 'Documento não encontrado.');
    }
}
