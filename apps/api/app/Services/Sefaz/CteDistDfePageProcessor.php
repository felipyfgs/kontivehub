<?php

namespace App\Services\Sefaz;

use App\Contracts\CteXmlSignatureValidator;
use App\Contracts\SecureObjectStore;
use App\Domain\Sefaz\DistDfeDocumentDto;
use App\Domain\Sefaz\DistDfePageDto;
use App\Enums\AdnDocumentType;
use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentArtifactQuality;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Enums\SignatureVerificationResult;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\ChannelSyncCursor;
use App\Models\ChannelSyncCursorTransition;
use App\Models\CteDocument;
use App\Models\CteEvent;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\FiscalDocumentQuarantine;
use App\Services\Adn\DocumentDecoder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Persiste lote CT-e DistDFe do cliente e só então avança NSU do channel_sync_cursors.
 *
 * - Interesses explícitos pelos 5 papéis não-emitentes
 * - emit = CNPJ consultado → quarentena UNEXPECTED_OWN_ISSUER_DOCUMENT
 * - Sem fallback TAKER
 * - Duas passagens (principal antes de eventos)
 * - Cursor = ultNSU da resposta após persistência integral
 */
final class CteDistDfePageProcessor
{
    public function __construct(
        private readonly DocumentDecoder $decoder,
        private readonly CteXmlProjectionParser $parser,
        private readonly SecureObjectStore $store,
        private readonly DistDfeResponseParser $schemaHelper,
        private readonly CteXmlSignatureValidator $signatureValidator,
    ) {}

    /**
     * @return array{documents: int, quarantined: int, advanced_to: int}
     */
    public function process(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfePageDto $page,
        bool $advanceSequentialCursor = true,
    ): array {
        if ($page->isAbuse()) {
            $from = $cursor->status?->value;
            $cursor->status = SyncCursorStatus::Blocked;
            $cursor->last_cstat = $page->cStat;
            $cursor->last_xmotivo = mb_substr($page->xMotivo, 0, 255);
            $cursor->last_error = 'Consumo indevido SEFAZ CT-e (cStat 656). Aguardar ≥1h.';
            $cursor->next_sync_at = now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
            $cursor->save();
            ChannelSyncCursorTransition::record(
                $cursor,
                'cstat_656_circuit',
                $from,
                SyncCursorStatus::Blocked->value,
                metadata: ['cstat' => '656'],
            );

            return ['documents' => 0, 'quarantined' => 0, 'advanced_to' => (int) $cursor->last_nsu];
        }

        if ($page->isAuthError()) {
            $from = $cursor->status?->value;
            $cursor->status = SyncCursorStatus::Blocked;
            $cursor->last_cstat = $page->cStat;
            $cursor->last_xmotivo = mb_substr($page->xMotivo, 0, 255);
            $cursor->last_error = 'Certificado/CNPJ divergente SEFAZ CT-e (cStat 593).';
            $cursor->next_sync_at = now()->addHours(1);
            $cursor->save();
            ChannelSyncCursorTransition::record(
                $cursor,
                'cstat_593_auth',
                $from,
                SyncCursorStatus::Blocked->value,
                metadata: ['cstat' => '593'],
            );

            return ['documents' => 0, 'quarantined' => 0, 'advanced_to' => (int) $cursor->last_nsu];
        }

        $storedObjectIds = [];

        try {
            return DB::transaction(function () use ($cursor, $establishment, $page, $advanceSequentialCursor, &$storedObjectIds) {
                $cursor = ChannelSyncCursor::query()->whereKey($cursor->id)->lockForUpdate()->firstOrFail();
                $persisted = 0;
                $quarantined = 0;

                $principals = [];
                $events = [];
                foreach ($page->documents as $doc) {
                    $family = $doc->schemaFamily !== 'unknown'
                        ? $doc->schemaFamily
                        : $this->schemaHelper->schemaFamily($doc->schema);
                    if (in_array($family, ['procEventoCTe', 'retEventoCTe'], true)) {
                        $events[] = [$doc, $family];
                    } else {
                        $principals[] = [$doc, $family];
                    }
                }

                foreach ($principals as [$doc, $family]) {
                    $r = $this->persistPrincipal($cursor, $establishment, $doc, $family, $storedObjectIds);
                    $persisted += $r['promoted'];
                    $quarantined += $r['quarantined'];
                }

                foreach ($events as [$doc, $family]) {
                    $r = $this->persistEvent($cursor, $establishment, $doc, $family, $storedObjectIds);
                    $persisted += $r['promoted'];
                    $quarantined += $r['quarantined'];
                }

                // Path de reparo (consNSU): persiste docs sem mutar o stream sequencial
                // (last_nsu, quiet period, saúde do cursor, circuit de decode).
                if (! $advanceSequentialCursor) {
                    return [
                        'documents' => $persisted,
                        'quarantined' => $quarantined,
                        'advanced_to' => (int) $cursor->last_nsu,
                    ];
                }

                // Autoridade do cursor sequencial: ultNSU da resposta (nunca max NSU de item)
                $newNsu = $page->isEmpty()
                    ? (int) $cursor->last_nsu
                    : max((int) $cursor->last_nsu, $page->ultNsu);

                $cursor->last_nsu = $newNsu;
                if ($page->maxNsu > 0) {
                    $cursor->max_nsu_seen = max((int) ($cursor->max_nsu_seen ?? 0), $page->maxNsu);
                }
                $cursor->last_cstat = $page->cStat;
                $cursor->last_xmotivo = mb_substr($page->xMotivo, 0, 255);
                $cursor->last_success_at = now();
                $cursor->last_error = null;
                $cursor->consecutive_decode_failures = 0;

                if ($page->isEndOfQueue()) {
                    $cursor->status = SyncCursorStatus::Idle;
                    $cursor->next_sync_at = now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
                }

                $cursor->save();

                return [
                    'documents' => $persisted,
                    'quarantined' => $quarantined,
                    'advanced_to' => $newNsu,
                ];
            });
        } catch (Throwable $e) {
            $this->deleteStoredObjects($storedObjectIds);

            // Circuit de decode falha só no DistDFe sequencial — reparo não bloqueia o stream.
            if ($e instanceof DocumentDecodeException && $advanceSequentialCursor) {
                $fresh = ChannelSyncCursor::query()->whereKey($cursor->id)->first();
                if ($fresh) {
                    $fresh->consecutive_decode_failures++;
                    $fresh->last_error = $e->getMessage();
                    $threshold = (int) config('sefaz.decode_failure_threshold', 5);
                    if ($fresh->consecutive_decode_failures >= $threshold) {
                        $fresh->status = SyncCursorStatus::Blocked;
                    }
                    $fresh->save();
                }
            } elseif ($e instanceof DocumentDecodeException) {
                Log::warning('sefaz.cte.repair.decode_failed', [
                    'channel_sync_cursor_id' => $cursor->id,
                    'office_id' => $cursor->office_id,
                    'establishment_id' => $cursor->establishment_id,
                    'error' => mb_substr($e->getMessage(), 0, 200),
                ]);
            }
            throw $e;
        }
    }

    /**
     * Persiste o resultado de consNSU conhecido sem mover o cursor sequencial
     * e sem efeitos colaterais de quiet/circuit/saúde do stream DistDFe.
     * O pipeline transacional/idempotente de documentos é reutilizado.
     *
     * @return array{documents: int, quarantined: int, advanced_to: int}
     */
    public function processKnownNsuRepair(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfePageDto $page,
    ): array {
        return $this->process($cursor, $establishment, $page, advanceSequentialCursor: false);
    }

    /**
     * @param  list<string>  $storedObjectIds
     * @return array{promoted: int, quarantined: int}
     */
    private function persistPrincipal(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfeDocumentDto $doc,
        string $family,
        array &$storedObjectIds,
    ): array {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $parsed = $this->parser->parse($decoded['bytes'], $family, $establishment->cnpj);
        $accessKey = $parsed['access_key'] ?? null;
        $consulted = strtoupper((string) $establishment->cnpj);
        $issuer = $parsed['issuer_cnpj'] ?? null;

        $signature = ($parsed['is_summary'] ?? false)
            ? SignatureVerificationResult::Valid
            : $this->signatureValidator->validate($decoded['bytes']);
        if ($signature === SignatureVerificationResult::Invalid) {
            $this->quarantine(
                $cursor,
                $establishment,
                $doc,
                $decoded,
                $accessKey,
                $issuer,
                QuarantineReason::InvalidSignature,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        // Contrato: DistDFe do cliente não distribui XML principal ao próprio emitente
        if ($issuer !== null && $issuer === $consulted) {
            $this->quarantine(
                $cursor,
                $establishment,
                $doc,
                $decoded,
                $accessKey,
                $issuer,
                QuarantineReason::UnexpectedOwnIssuerDocument,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        /** @var list<FiscalRole> $roles */
        $roles = array_values(array_filter(
            $parsed['matched_roles'] ?? [],
            fn (FiscalRole $r) => $r->isCteClientInterest()
        ));

        if ($roles === [] && ! ($parsed['is_summary'] ?? false)) {
            // Sem papel elegível → quarentena (sem inventar TAKER)
            $this->quarantine(
                $cursor,
                $establishment,
                $doc,
                $decoded,
                $accessKey,
                $issuer,
                QuarantineReason::UnmatchedFiscalRole,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        // Resumo sem papel: ainda assim preserva com REVIEW se houver chave
        if ($roles === [] && ($parsed['is_summary'] ?? false)) {
            $roles = []; // interesse sem papel tipado não é criado
        }

        $dfe = $this->findOrStoreDocument(
            $cursor,
            $doc,
            $decoded,
            AdnDocumentType::Cte,
            $accessKey,
            $storedObjectIds,
        );

        $this->recordAcquisition($cursor, $establishment, $dfe, $doc, $decoded['sha256'], $signature);

        foreach ($roles as $role) {
            DocumentInterest::query()->firstOrCreate(
                [
                    'dfe_document_id' => $dfe->id,
                    'establishment_id' => $establishment->id,
                    'fiscal_role' => $role->value,
                    'channel' => CaptureChannel::CteDistDfe->value,
                ],
                [
                    'office_id' => $cursor->office_id,
                    'environment' => $cursor->environment,
                    'nsu' => $doc->nsu,
                    'direction' => DocumentDirection::fromFiscalRole($role)->value,
                ]
            );
        }

        // Idempotência de NSU: se nenhum papel, ainda registra interesse nulo? Não — só projeção
        if ($accessKey) {
            $this->upsertProjection($cursor, $dfe, $parsed, $doc->schema, $roles);
        }

        return ['promoted' => 1, 'quarantined' => 0];
    }

    /**
     * @param  list<string>  $storedObjectIds
     * @return array{promoted: int, quarantined: int}
     */
    private function persistEvent(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfeDocumentDto $doc,
        string $family,
        array &$storedObjectIds,
    ): array {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $parsed = $this->parser->parse($decoded['bytes'], $family, $establishment->cnpj);
        $accessKey = $parsed['access_key'] ?? null;
        $signature = $this->signatureValidator->validate($decoded['bytes']);
        if ($signature === SignatureVerificationResult::Invalid) {
            $this->quarantine(
                $cursor,
                $establishment,
                $doc,
                $decoded,
                $accessKey,
                $parsed['issuer_cnpj'] ?? null,
                QuarantineReason::InvalidSignature,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $dfe = $this->findOrStoreDocument(
            $cursor,
            $doc,
            $decoded,
            AdnDocumentType::Unknown,
            $accessKey,
            $storedObjectIds,
        );

        $this->recordAcquisition($cursor, $establishment, $dfe, $doc, $decoded['sha256'], $signature);

        DocumentInterest::query()->firstOrCreate(
            [
                'establishment_id' => $establishment->id,
                'environment' => $cursor->environment,
                'channel' => CaptureChannel::CteDistDfe->value,
                'nsu' => $doc->nsu,
                'fiscal_role' => null,
            ],
            [
                'office_id' => $cursor->office_id,
                'dfe_document_id' => $dfe->id,
                'direction' => DocumentDirection::Unknown->value,
            ]
        );

        $parent = null;
        if ($accessKey) {
            $parent = CteDocument::query()
                ->where('office_id', $cursor->office_id)
                ->where('access_key', $accessKey)
                ->where('is_summary', false)
                ->first();

            if ($parent && ($parsed['status'] ?? null) === 'CANCELLED') {
                $parent->update(['status' => 'CANCELLED']);
            }

            if ($parent === null) {
                $this->quarantine(
                    $cursor,
                    $establishment,
                    $doc,
                    $decoded,
                    $accessKey,
                    $parsed['issuer_cnpj'] ?? null,
                    QuarantineReason::OrphanEvent,
                    $storedObjectIds,
                    $dfe,
                );

                return ['promoted' => 0, 'quarantined' => 1];
            }

            CteEvent::query()->updateOrCreate(
                [
                    'office_id' => $cursor->office_id,
                    'access_key' => $accessKey,
                    'event_type' => $parsed['event_type'] ?? 'UNKNOWN',
                    'sequence' => $parsed['event_sequence'] ?? 0,
                ],
                [
                    'dfe_document_id' => $dfe->id,
                    'cte_document_id' => $parent->id,
                    'protocol_number' => $parsed['protocol_number'] ?? null,
                    'cstat' => $parsed['official_status_code'] ?? null,
                    'status' => $parsed['status'] ?? 'EVENT',
                    'event_at' => $parsed['issued_at'] ?? now(),
                ]
            );
        }

        return ['promoted' => 1, 'quarantined' => 0];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  list<FiscalRole>  $roles
     */
    private function upsertProjection(
        ChannelSyncCursor $cursor,
        DfeDocument $dfe,
        array $parsed,
        string $schemaHint,
        array $roles,
    ): void {
        $primary = count($roles) === 1
            ? $roles[0]
            : ($parsed['fiscal_role'] ?? null);
        $direction = DocumentDirection::fromFiscalRole($primary);
        $isSummary = (bool) ($parsed['is_summary'] ?? false);

        CteDocument::query()->updateOrCreate(
            [
                'office_id' => $cursor->office_id,
                'access_key' => $parsed['access_key'],
                'is_summary' => $isSummary,
            ],
            [
                'dfe_document_id' => $dfe->id,
                'number' => $parsed['number'] ?? null,
                'series' => $parsed['series'] ?? null,
                'model' => $parsed['model'] ?? '57',
                'issuer_cnpj' => $parsed['issuer_cnpj'] ?? null,
                'issuer_name' => $parsed['issuer_name'] ?? null,
                'taker_cnpj' => $parsed['taker_cnpj'] ?? null,
                'taker_name' => $parsed['taker_name'] ?? null,
                'effective_taker_cnpj' => $parsed['effective_taker_cnpj'] ?? null,
                'sender_cnpj' => $parsed['sender_cnpj'] ?? null,
                'recipient_cnpj' => $parsed['recipient_cnpj'] ?? null,
                'expeditor_cnpj' => $parsed['expeditor_cnpj'] ?? null,
                'expeditor_name' => $parsed['expeditor_name'] ?? null,
                'receiver_cnpj' => $parsed['receiver_cnpj'] ?? null,
                'receiver_name' => $parsed['receiver_name'] ?? null,
                'fiscal_role' => $primary,
                'direction' => $direction,
                'issued_at' => $parsed['issued_at'] ?? null,
                'total_amount' => $parsed['total_amount'] ?? null,
                'status' => $parsed['status'] ?? 'UNKNOWN',
                'official_status_code' => $parsed['official_status_code'] ?? null,
                'protocol_number' => $parsed['protocol_number'] ?? null,
                'schema_hint' => $schemaHint,
                'schema_version' => $parsed['schema_version'] ?? null,
            ]
        );
    }

    private function recordAcquisition(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DfeDocument $dfe,
        DistDfeDocumentDto $doc,
        string $sha256,
        SignatureVerificationResult $signature,
    ): void {
        DocumentAcquisition::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'source' => DocumentAcquisitionSource::CteDistNsu->value,
                'sha256' => $sha256,
            ],
            [
                'office_id' => $cursor->office_id,
                'access_key' => $dfe->access_key,
                'channel' => CaptureChannel::CteDistDfe,
                'nsu' => $doc->nsu,
                'artifact_quality' => DocumentArtifactQuality::Original,
                'signature_result' => $signature,
                'is_canonical' => true,
                'establishment_id' => $establishment->id,
            ]
        );
    }

    /**
     * @param  array{bytes: string, sha256: string}  $decoded
     * @param  list<string>  $storedObjectIds
     */
    private function quarantine(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfeDocumentDto $doc,
        array $decoded,
        ?string $accessKey,
        ?string $issuerCnpj,
        QuarantineReason $reason,
        array &$storedObjectIds,
        ?DfeDocument $existingDfe = null,
    ): void {
        $identity = [
            'office_id' => $cursor->office_id,
            'sha256' => $decoded['sha256'],
        ];

        $objectId = $existingDfe?->vault_object_id;
        if ($objectId === null) {
            $objectId = $this->store->put($decoded['bytes'], $identity + ['purpose' => 'quarantine']);
            $storedObjectIds[] = $objectId;
        }

        FiscalDocumentQuarantine::query()->firstOrCreate(
            [
                'office_id' => $cursor->office_id,
                'sha256' => $decoded['sha256'],
                'source' => DocumentAcquisitionSource::CteDistNsu->value,
                'nsu' => $doc->nsu,
            ],
            [
                'vault_object_id' => $objectId,
                'byte_size' => strlen($decoded['bytes']),
                'access_key' => $accessKey,
                'issuer_cnpj' => $issuerCnpj,
                'model' => '57',
                'schema_family' => $doc->schemaFamily !== 'unknown' ? $doc->schemaFamily : $doc->schema,
                'reason' => $reason->value,
                'channel' => CaptureChannel::CteDistDfe->value,
                'resolution_status' => QuarantineResolutionStatus::Open->value,
                'metadata' => [
                    'establishment_id' => $establishment->id,
                    'channel_sync_cursor_id' => $cursor->id,
                ],
            ]
        );
    }

    /**
     * @param  array{bytes: string, sha256: string}  $decoded
     * @param  list<string>  $storedObjectIds
     */
    private function findOrStoreDocument(
        ChannelSyncCursor $cursor,
        DistDfeDocumentDto $doc,
        array $decoded,
        AdnDocumentType $docType,
        ?string $accessKey,
        array &$storedObjectIds,
    ): DfeDocument {
        $identity = [
            'office_id' => $cursor->office_id,
            'sha256' => $decoded['sha256'],
        ];

        $existing = DfeDocument::query()->where($identity)->first();
        if ($existing !== null) {
            return $existing;
        }

        $objectId = $this->store->put($decoded['bytes'], $identity);

        try {
            $dfe = DfeDocument::query()->firstOrCreate($identity, [
                'document_type' => $docType,
                'schema_version' => $doc->schema,
                'access_key' => $accessKey,
                'vault_object_id' => $objectId,
                'byte_size' => strlen($decoded['bytes']),
                'parse_status' => $accessKey ? 'OK' : 'REVIEW',
                'parse_alert' => $accessKey ? null : 'Chave CT-e não extraída; XML preservado.',
            ]);
        } catch (Throwable $e) {
            $this->deleteStoredObjects([$objectId]);
            throw $e;
        }

        if (! $dfe->wasRecentlyCreated) {
            $this->deleteStoredObjects([$objectId]);

            return $dfe;
        }

        $storedObjectIds[] = $objectId;

        return $dfe;
    }

    /**
     * @param  list<string>  $objectIds
     */
    private function deleteStoredObjects(array $objectIds): void
    {
        foreach (array_unique($objectIds) as $objectId) {
            try {
                $this->store->delete($objectId);
            } catch (Throwable $cleanupError) {
                report($cleanupError);
            }
        }
    }
}
