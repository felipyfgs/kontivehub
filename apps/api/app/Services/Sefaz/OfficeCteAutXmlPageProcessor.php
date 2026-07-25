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
use App\Models\CteDocument;
use App\Models\CteEvent;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\FiscalDocumentQuarantine;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;
use App\Services\Adn\DocumentDecoder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processa página CTeDistribuicaoDFe no contexto do escritório (autXML).
 * Reutiliza A1/identidade do office; nunca ClientCredential.
 * Roteia por emit/CNPJ; exige presença do query_cnpj em autXML.
 */
final class OfficeCteAutXmlPageProcessor
{
    public function __construct(
        private readonly DocumentDecoder $decoder,
        private readonly CteXmlProjectionParser $parser,
        private readonly CteArtifactQualityClassifier $quality,
        private readonly SecureObjectStore $store,
        private readonly DistDfeResponseParser $schemaHelper,
        private readonly CteXmlSignatureValidator $signatureValidator,
    ) {}

    /**
     * @return array{documents: int, quarantined: int, advanced_to: int}
     */
    public function process(
        OfficeDistributionCursor $cursor,
        DistDfePageDto $page,
        ?OfficeDistributionRun $run = null,
    ): array {
        if ($page->isAbuse()) {
            $cursor->status = SyncCursorStatus::Blocked;
            $cursor->last_cstat = $page->cStat;
            $cursor->last_xmotivo = mb_substr($page->xMotivo, 0, 255);
            $cursor->last_error = 'Consumo indevido SEFAZ CT-e autXML (cStat 656).';
            $cursor->next_sync_at = now()->addHours((float) config('sefaz.cte_autxml.circuit_breaker_hours', 1));
            $cursor->save();

            return ['documents' => 0, 'quarantined' => 0, 'advanced_to' => (int) $cursor->last_nsu];
        }

        $storedObjectIds = [];

        try {
            return DB::transaction(function () use ($cursor, $page, $run, &$storedObjectIds) {
                $cursor = OfficeDistributionCursor::query()->whereKey($cursor->id)->lockForUpdate()->firstOrFail();
                $queryCnpj = strtoupper($cursor->query_cnpj);
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
                    $r = $this->persistPrincipal($cursor, $doc, $family, $queryCnpj, $storedObjectIds);
                    $persisted += $r['promoted'];
                    $quarantined += $r['quarantined'];
                }

                foreach ($events as [$doc, $family]) {
                    $r = $this->persistEvent($cursor, $doc, $family, $storedObjectIds);
                    $persisted += $r['promoted'];
                    $quarantined += $r['quarantined'];
                }

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
                $cursor->last_heartbeat_at = now();
                $cursor->last_error = null;
                $cursor->consecutive_decode_failures = 0;

                if ($cursor->activated_at === null) {
                    $cursor->activated_at = now();
                }

                if ($page->isEndOfQueue()) {
                    $cursor->status = SyncCursorStatus::Idle;
                    $cursor->next_sync_at = now()->addHours((float) config('sefaz.cte_autxml.quiet_hours_after_empty', 1));
                }

                $cursor->save();

                if ($run !== null) {
                    $run->documents_persisted = (int) $run->documents_persisted + $persisted;
                    $run->documents_quarantined = (int) $run->documents_quarantined + $quarantined;
                    $run->to_nsu = $newNsu;
                    $run->last_cstat = $page->cStat;
                    $run->save();
                }

                return [
                    'documents' => $persisted,
                    'quarantined' => $quarantined,
                    'advanced_to' => $newNsu,
                ];
            });
        } catch (Throwable $e) {
            $this->deleteStoredObjects($storedObjectIds);

            if ($e instanceof DocumentDecodeException) {
                $fresh = OfficeDistributionCursor::query()->whereKey($cursor->id)->first();
                if ($fresh) {
                    $fresh->consecutive_decode_failures++;
                    $fresh->last_error = $e->getMessage();
                    $threshold = (int) config('sefaz.cte_autxml.decode_failure_threshold', 5);
                    if ($fresh->consecutive_decode_failures >= $threshold) {
                        $fresh->status = SyncCursorStatus::Blocked;
                    }
                    $fresh->save();
                }
            }
            throw $e;
        }
    }

    /**
     * @param  list<string>  $storedObjectIds
     * @return array{promoted: int, quarantined: int}
     */
    private function persistPrincipal(
        OfficeDistributionCursor $cursor,
        DistDfeDocumentDto $doc,
        string $family,
        string $queryCnpj,
        array &$storedObjectIds,
    ): array {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $parsed = $this->parser->parse($decoded['bytes'], $family, null);
        $accessKey = $parsed['access_key'] ?? null;
        $issuerCnpj = $parsed['issuer_cnpj'] ?? null;
        $autXml = $parsed['autxml_cnpjs'] ?? [];
        $signature = $this->signatureValidator->validate(
            $decoded['bytes'],
            allowOfficialRedaction: (bool) ($parsed['has_official_redaction'] ?? false),
        );
        if ($signature === SignatureVerificationResult::Invalid) {
            $this->quarantine(
                $cursor,
                $doc,
                $decoded,
                $accessKey,
                $issuerCnpj,
                QuarantineReason::InvalidSignature,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if (! in_array($queryCnpj, $autXml, true)) {
            $this->quarantine(
                $cursor,
                $doc,
                $decoded,
                $accessKey,
                $issuerCnpj,
                empty($autXml) ? QuarantineReason::AutXmlTagMissing : QuarantineReason::AutXmlTagDivergent,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if ($issuerCnpj === null || $issuerCnpj === '') {
            $this->quarantine(
                $cursor,
                $doc,
                $decoded,
                $accessKey,
                null,
                QuarantineReason::SchemaIncomplete,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $issuerEst = Establishment::query()
            ->where('office_id', $cursor->office_id)
            ->where('cnpj', $issuerCnpj)
            ->where('is_active', true)
            ->first();

        if ($issuerEst === null) {
            $this->quarantine(
                $cursor,
                $doc,
                $decoded,
                $accessKey,
                $issuerCnpj,
                QuarantineReason::UnmatchedIssuer,
                $storedObjectIds,
            );

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $quality = $this->quality->classify(
            $parsed,
            fromOfficialAutXmlChannel: true,
            signatureValid: $signature === SignatureVerificationResult::Valid,
            signatureChecked: true,
        );

        $dfe = $this->findOrStoreDocument(
            $cursor,
            $doc,
            $decoded,
            AdnDocumentType::Cte,
            $accessKey,
            $storedObjectIds,
        );

        $this->recordAcquisition($cursor, $dfe, $doc, $decoded['sha256'], $quality['quality'], $quality['signature'], $issuerEst->id);

        // ISSUER/OUT para o emitente
        DocumentInterest::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'establishment_id' => $issuerEst->id,
                'fiscal_role' => FiscalRole::Issuer->value,
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
            ],
            [
                'office_id' => $cursor->office_id,
                'environment' => $cursor->environment,
                'nsu' => $doc->nsu,
                'direction' => DocumentDirection::Out->value,
            ]
        );

        // Interesses IN para outros clientes do mesmo office em papéis de interesse
        $this->createParticipantInterests($cursor, $dfe, $doc, $parsed, $issuerEst->id);

        if ($accessKey) {
            $this->upsertProjection($cursor, $dfe, $parsed, $doc->schema, FiscalRole::Issuer);
        }

        return ['promoted' => 1, 'quarantined' => 0];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function createParticipantInterests(
        OfficeDistributionCursor $cursor,
        DfeDocument $dfe,
        DistDfeDocumentDto $doc,
        array $parsed,
        int $issuerEstablishmentId,
    ): void {
        $roleCnpj = [
            FiscalRole::Sender->value => $parsed['sender_cnpj'] ?? null,
            FiscalRole::Recipient->value => $parsed['recipient_cnpj'] ?? null,
            FiscalRole::Expeditor->value => $parsed['expeditor_cnpj'] ?? null,
            FiscalRole::Receiver->value => $parsed['receiver_cnpj'] ?? null,
            FiscalRole::Taker->value => $parsed['effective_taker_cnpj'] ?? null,
        ];

        foreach ($roleCnpj as $roleValue => $cnpj) {
            if ($cnpj === null || $cnpj === '') {
                continue;
            }
            $est = Establishment::query()
                ->where('office_id', $cursor->office_id)
                ->where('cnpj', $cnpj)
                ->where('is_active', true)
                ->first();
            if ($est === null || $est->id === $issuerEstablishmentId) {
                continue;
            }
            $role = FiscalRole::from($roleValue);
            DocumentInterest::query()->firstOrCreate(
                [
                    'dfe_document_id' => $dfe->id,
                    'establishment_id' => $est->id,
                    'fiscal_role' => $role->value,
                    'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                ],
                [
                    'office_id' => $cursor->office_id,
                    'environment' => $cursor->environment,
                    'nsu' => $doc->nsu,
                    'direction' => DocumentDirection::In->value,
                ]
            );
        }
    }

    /**
     * @param  list<string>  $storedObjectIds
     * @return array{promoted: int, quarantined: int}
     */
    private function persistEvent(
        OfficeDistributionCursor $cursor,
        DistDfeDocumentDto $doc,
        string $family,
        array &$storedObjectIds,
    ): array {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $parsed = $this->parser->parse($decoded['bytes'], $family, null);
        $accessKey = $parsed['access_key'] ?? null;
        $signature = $this->signatureValidator->validate($decoded['bytes']);
        if ($signature === SignatureVerificationResult::Invalid) {
            $this->quarantine(
                $cursor,
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

        $quality = $this->quality->classify(
            $parsed,
            true,
            $signature === SignatureVerificationResult::Valid,
            true,
        );
        $this->recordAcquisition($cursor, $dfe, $doc, $decoded['sha256'], $quality['quality'], $quality['signature'], null);

        if (! $accessKey) {
            return ['promoted' => 1, 'quarantined' => 0];
        }

        $parent = CteDocument::query()
            ->where('office_id', $cursor->office_id)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->first();

        if ($parent === null) {
            $this->quarantine(
                $cursor,
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

        if (($parsed['status'] ?? null) === 'CANCELLED') {
            $parent->update(['status' => 'CANCELLED']);
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
                'event_at' => now(),
            ]
        );

        return ['promoted' => 1, 'quarantined' => 0];
    }

    /**
     * @param  array<string, mixed>  $parsed
     */
    private function upsertProjection(
        OfficeDistributionCursor $cursor,
        DfeDocument $dfe,
        array $parsed,
        string $schemaHint,
        FiscalRole $primary,
    ): void {
        CteDocument::query()->updateOrCreate(
            [
                'office_id' => $cursor->office_id,
                'access_key' => $parsed['access_key'],
                'is_summary' => false,
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
                'direction' => DocumentDirection::Out,
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
        OfficeDistributionCursor $cursor,
        DfeDocument $dfe,
        DistDfeDocumentDto $doc,
        string $sha256,
        DocumentArtifactQuality $quality,
        $signature,
        ?int $establishmentId,
    ): void {
        $existingCanonical = DocumentAcquisition::query()
            ->where('dfe_document_id', $dfe->id)
            ->where('is_canonical', true)
            ->first();

        $isCanonical = $existingCanonical === null
            || $this->quality->prefersAsCanonical($quality, $existingCanonical->artifact_quality);

        if ($isCanonical && $existingCanonical !== null && $existingCanonical->sha256 === $sha256) {
            // mesma hash — pode promover qualidade se melhor
        } elseif ($isCanonical && $existingCanonical !== null && $existingCanonical->sha256 !== $sha256) {
            // bytes divergentes — não vira canônico cegamente se original existe
            if ($existingCanonical->artifact_quality === DocumentArtifactQuality::Original) {
                $isCanonical = false;
            } else {
                $existingCanonical->update(['is_canonical' => false]);
            }
        }

        DocumentAcquisition::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'source' => DocumentAcquisitionSource::CteAutXmlDistNsu->value,
                'sha256' => $sha256,
            ],
            [
                'office_id' => $cursor->office_id,
                'access_key' => $dfe->access_key,
                'channel' => CaptureChannel::CteAutXmlDistDfe,
                'nsu' => $doc->nsu,
                'artifact_quality' => $quality,
                'signature_result' => $signature,
                'is_canonical' => $isCanonical,
                'establishment_id' => $establishmentId,
                'office_distribution_cursor_id' => $cursor->id,
            ]
        );
    }

    /**
     * @param  array{bytes: string, sha256: string}  $decoded
     * @param  list<string>  $storedObjectIds
     */
    private function quarantine(
        OfficeDistributionCursor $cursor,
        DistDfeDocumentDto $doc,
        array $decoded,
        ?string $accessKey,
        ?string $issuerCnpj,
        QuarantineReason $reason,
        array &$storedObjectIds,
        ?DfeDocument $existingDfe = null,
    ): void {
        $objectId = $existingDfe?->vault_object_id;
        if ($objectId === null) {
            $objectId = $this->store->put($decoded['bytes'], [
                'office_id' => $cursor->office_id,
                'sha256' => $decoded['sha256'],
                'purpose' => 'quarantine',
            ]);
            $storedObjectIds[] = $objectId;
        }

        FiscalDocumentQuarantine::query()->firstOrCreate(
            [
                'office_id' => $cursor->office_id,
                'sha256' => $decoded['sha256'],
                'source' => DocumentAcquisitionSource::CteAutXmlDistNsu->value,
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
                'channel' => CaptureChannel::CteAutXmlDistDfe->value,
                'office_distribution_cursor_id' => $cursor->id,
                'resolution_status' => QuarantineResolutionStatus::Open->value,
                'metadata' => ['office_distribution_cursor_id' => $cursor->id],
            ]
        );
    }

    /**
     * @param  array{bytes: string, sha256: string}  $decoded
     * @param  list<string>  $storedObjectIds
     */
    private function findOrStoreDocument(
        OfficeDistributionCursor $cursor,
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
