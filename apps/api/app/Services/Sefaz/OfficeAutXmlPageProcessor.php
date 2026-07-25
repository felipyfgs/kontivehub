<?php

namespace App\Services\Sefaz;

use App\Contracts\SecureObjectStore;
use App\Domain\Sefaz\DistDfeDocumentDto;
use App\Domain\Sefaz\DistDfePageDto;
use App\Enums\AdnDocumentType;
use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Enums\OfficeAutXmlEnrollmentStatus;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\FiscalDocumentQuarantine;
use App\Models\NfeDocument;
use App\Models\NfeEvent;
use App\Models\OfficeAutXmlEnrollment;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;
use App\Services\Adn\DocumentDecoder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Processa página DistDFe no contexto do escritório (autXML).
 * Nunca classifica o escritório como Cliente; não enfileira ciência/manifestação.
 */
final class OfficeAutXmlPageProcessor
{
    public function __construct(
        private readonly DocumentDecoder $decoder,
        private readonly NfeXmlProjectionParser $parser,
        private readonly SecureObjectStore $store,
        private readonly DistDfeResponseParser $schemaHelper,
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
            app(AutXmlCircuitBreaker::class)->openForCursor($cursor, $page->cStat, $page->xMotivo);

            return ['documents' => 0, 'quarantined' => 0, 'advanced_to' => (int) $cursor->last_nsu];
        }

        $storedObjectIds = [];

        try {
            return DB::transaction(function () use ($cursor, $page, $run, &$storedObjectIds) {
                $cursor = OfficeDistributionCursor::query()->whereKey($cursor->id)->lockForUpdate()->firstOrFail();
                $queryCnpj = strtoupper($cursor->query_cnpj);
                $persisted = 0;
                $quarantined = 0;

                // Duas passagens: notas antes de eventos
                $notes = [];
                $events = [];
                foreach ($page->documents as $doc) {
                    $family = $doc->schemaFamily !== 'unknown'
                        ? $doc->schemaFamily
                        : $this->schemaHelper->schemaFamily($doc->schema);
                    if (in_array($family, ['procEventoNFe', 'resEvento'], true)) {
                        $events[] = [$doc, $family];
                    } else {
                        $notes[] = [$doc, $family];
                    }
                }

                foreach ($notes as [$doc, $family]) {
                    $r = $this->persistNote($cursor, $doc, $family, $queryCnpj, $storedObjectIds);
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
                    $cursor->next_sync_at = now()->addHours((float) config('sefaz.autxml.quiet_hours_after_empty', 1));
                }

                $cursor->save();

                if ($run !== null) {
                    $run->to_nsu = $newNsu;
                    $run->documents_persisted = (int) $run->documents_persisted + $persisted;
                    $run->documents_quarantined = (int) $run->documents_quarantined + $quarantined;
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
                    $fresh->last_error = mb_substr($e->getMessage(), 0, 500);
                    $threshold = (int) config('sefaz.autxml.decode_failure_threshold', 5);
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
    private function persistNote(
        OfficeDistributionCursor $cursor,
        DistDfeDocumentDto $doc,
        string $family,
        string $queryCnpj,
        array &$storedObjectIds,
    ): array {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $parsed = $this->parser->parse($decoded['bytes'], $family);
        $sha = $decoded['sha256'];
        $accessKey = $parsed['access_key'] ?? null;
        $model = (string) ($parsed['model'] ?? '55');
        $issuer = $parsed['issuer_cnpj'] ?? null;
        $recipient = $parsed['recipient_cnpj'] ?? null;
        /** @var list<string> $autXml */
        $autXml = $parsed['aut_xml_cnpjs'] ?? [];

        // resNFe no papel de terceiro → quarentena (XML incompleto)
        if ($family === 'resNFe' || ($parsed['is_summary'] ?? false)) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::SummaryOnly, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if ($model !== '55') {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::SchemaIncomplete, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if (($parsed['tp_nf'] ?? null) !== null && (string) $parsed['tp_nf'] !== '1') {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::SchemaIncomplete, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if ($autXml === []) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::AutXmlTagMissing, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if (! in_array($queryCnpj, $autXml, true)) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::AutXmlTagDivergent, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        // Escritório também destinatário → não capturar full sem manifesto (design)
        if ($recipient !== null && $recipient === $queryCnpj) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::OfficeAlsoRecipient, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        if ($issuer === null) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::UnmatchedIssuer, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $issuerEstab = Establishment::query()
            ->where('office_id', $cursor->office_id)
            ->where('cnpj', $issuer)
            ->where('is_active', true)
            ->first();

        if ($issuerEstab === null) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::UnmatchedIssuer, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $enrollment = OfficeAutXmlEnrollment::query()
            ->where('office_id', $cursor->office_id)
            ->where('office_fiscal_identity_id', $cursor->office_fiscal_identity_id)
            ->where('establishment_id', $issuerEstab->id)
            ->whereIn('status', [
                OfficeAutXmlEnrollmentStatus::Pending->value,
                OfficeAutXmlEnrollmentStatus::Confirmed->value,
            ])
            ->first();

        // Sem enrollment: ainda assim promove se emitente cadastrado (onboarding pode confirmar depois)
        // Design: "emitente associado a estabelecimento ativo/enrolled" — exige enrollment
        if ($enrollment === null) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, $issuer, $recipient, $family, QuarantineReason::EnrollmentMissing, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $dfe = $this->findOrStoreDocument($cursor, $doc, $decoded, AdnDocumentType::Nfe, $accessKey, $storedObjectIds);

        DocumentAcquisition::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'source' => DocumentAcquisitionSource::AutXmlDistNsu->value,
                'sha256' => $sha,
            ],
            [
                'office_id' => $cursor->office_id,
                'access_key' => $accessKey,
                'channel' => CaptureChannel::NfeAutXmlDistDfe,
                'nsu' => $doc->nsu,
                'is_canonical' => true,
                'establishment_id' => $issuerEstab->id,
                'office_distribution_cursor_id' => $cursor->id,
                'metadata' => ['schema' => $doc->schema],
            ]
        );

        DocumentInterest::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'establishment_id' => $issuerEstab->id,
                'fiscal_role' => FiscalRole::Issuer->value,
                'channel' => CaptureChannel::NfeAutXmlDistDfe->value,
            ],
            [
                'office_id' => $cursor->office_id,
                'nsu' => $doc->nsu,
                'environment' => $cursor->environment,
                'direction' => DocumentDirection::Out,
            ]
        );

        NfeDocument::query()->updateOrCreate(
            [
                'office_id' => $cursor->office_id,
                'access_key' => $accessKey,
                'is_summary' => false,
            ],
            [
                'dfe_document_id' => $dfe->id,
                'number' => $parsed['number'] ?? null,
                'series' => $parsed['series'] ?? null,
                'model' => $model,
                'issuer_cnpj' => $issuer,
                'issuer_name' => $parsed['issuer_name'] ?? null,
                'recipient_cnpj' => $recipient,
                'recipient_name' => $parsed['recipient_name'] ?? null,
                'fiscal_role' => FiscalRole::Issuer,
                'direction' => DocumentDirection::Out,
                'issued_at' => $parsed['issued_at'] ?? null,
                'total_amount' => $parsed['total_amount'] ?? null,
                'status' => $parsed['status'] ?? 'ACTIVE',
                'official_status_code' => $parsed['official_status_code'] ?? null,
                'manifestation_status' => null,
                'schema_hint' => 'autxml:'.$family,
                'acquisition_source' => DocumentAcquisitionSource::AutXmlDistNsu->value,
            ]
        );

        // Destinatário também cliente do escritório → interesse TAKER/IN adicional
        if ($recipient !== null) {
            $takerEstab = Establishment::query()
                ->where('office_id', $cursor->office_id)
                ->where('cnpj', $recipient)
                ->where('is_active', true)
                ->where('id', '!=', $issuerEstab->id)
                ->first();
            if ($takerEstab !== null) {
                DocumentInterest::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $dfe->id,
                        'establishment_id' => $takerEstab->id,
                        'fiscal_role' => FiscalRole::Taker->value,
                        'channel' => CaptureChannel::NfeAutXmlDistDfe->value,
                    ],
                    [
                        'office_id' => $cursor->office_id,
                        'nsu' => $doc->nsu,
                        'environment' => $cursor->environment,
                        'direction' => DocumentDirection::In,
                    ]
                );
            }
        }

        // Atualiza enrollment observado
        if ($enrollment->first_seen_at === null) {
            $enrollment->first_seen_at = now();
        }
        $enrollment->last_seen_at = now();
        $enrollment->save();

        return ['promoted' => 1, 'quarantined' => 0];
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
        $parsed = $this->parser->parse($decoded['bytes'], $family);
        $accessKey = $parsed['access_key'] ?? null;
        $sha = $decoded['sha256'];

        if ($accessKey === null) {
            $this->quarantine($cursor, $doc, $decoded, $sha, null, null, null, $family, QuarantineReason::OrphanEvent, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $parent = NfeDocument::query()
            ->where('office_id', $cursor->office_id)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->first();

        if ($parent === null) {
            $this->quarantine($cursor, $doc, $decoded, $sha, $accessKey, null, null, $family, QuarantineReason::OrphanEvent, $storedObjectIds);

            return ['promoted' => 0, 'quarantined' => 1];
        }

        $dfe = $this->findOrStoreDocument($cursor, $doc, $decoded, AdnDocumentType::NfeEvent, $accessKey, $storedObjectIds);

        DocumentAcquisition::query()->firstOrCreate(
            [
                'dfe_document_id' => $dfe->id,
                'source' => DocumentAcquisitionSource::AutXmlDistNsu->value,
                'sha256' => $sha,
            ],
            [
                'office_id' => $cursor->office_id,
                'access_key' => $accessKey,
                'channel' => CaptureChannel::NfeAutXmlDistDfe,
                'nsu' => $doc->nsu,
                'is_canonical' => true,
                'office_distribution_cursor_id' => $cursor->id,
                'metadata' => ['schema' => $doc->schema, 'event' => true],
            ]
        );

        NfeEvent::query()->firstOrCreate(
            [
                'office_id' => $cursor->office_id,
                'dfe_document_id' => $dfe->id,
            ],
            [
                'access_key' => $accessKey,
                'event_type' => $parsed['event_type'] ?? null,
                'event_at' => $parsed['event_at'] ?? null,
                'status' => $parsed['status'] ?? null,
            ]
        );

        if (($parsed['status'] ?? null) === 'CANCELLED') {
            $parent->status = 'CANCELLED';
            $parent->save();
        }

        return ['promoted' => 1, 'quarantined' => 0];
    }

    /**
     * @param  array{bytes: string, sha256: string}  $decoded
     * @param  list<string>  $storedObjectIds
     */
    private function quarantine(
        OfficeDistributionCursor $cursor,
        DistDfeDocumentDto $doc,
        array $decoded,
        string $sha,
        ?string $accessKey,
        ?string $issuer,
        ?string $recipient,
        string $family,
        QuarantineReason $reason,
        array &$storedObjectIds,
    ): void {
        $existing = FiscalDocumentQuarantine::query()
            ->where('office_id', $cursor->office_id)
            ->where('sha256', $sha)
            ->where('source', DocumentAcquisitionSource::AutXmlDistNsu->value)
            ->where('nsu', $doc->nsu)
            ->first();
        if ($existing !== null) {
            return;
        }

        $objectId = $this->store->put($decoded['bytes'], [
            'office_id' => $cursor->office_id,
            'sha256' => $sha,
            'purpose' => 'quarantine',
        ]);
        $storedObjectIds[] = $objectId;

        FiscalDocumentQuarantine::query()->create([
            'office_id' => $cursor->office_id,
            'sha256' => $sha,
            'vault_object_id' => $objectId,
            'byte_size' => strlen($decoded['bytes']),
            'access_key' => $accessKey,
            'issuer_cnpj' => $issuer,
            'recipient_cnpj' => $recipient,
            'schema_family' => $family,
            'reason' => $reason,
            'source' => DocumentAcquisitionSource::AutXmlDistNsu,
            'channel' => CaptureChannel::NfeAutXmlDistDfe,
            'nsu' => $doc->nsu,
            'office_distribution_cursor_id' => $cursor->id,
            'resolution_status' => QuarantineResolutionStatus::Open,
            'metadata' => ['schema' => $doc->schema],
        ]);
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
        $existing = DfeDocument::query()
            ->where('office_id', $cursor->office_id)
            ->where('sha256', $decoded['sha256'])
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $objectId = $this->store->put($decoded['bytes'], [
            'office_id' => $cursor->office_id,
            'sha256' => $decoded['sha256'],
        ]);
        $storedObjectIds[] = $objectId;

        return DfeDocument::query()->create([
            'office_id' => $cursor->office_id,
            'sha256' => $decoded['sha256'],
            'document_type' => $docType,
            'schema_version' => $doc->schema,
            'access_key' => $accessKey,
            'vault_object_id' => $objectId,
            'byte_size' => strlen($decoded['bytes']),
            'parse_status' => 'OK',
        ]);
    }

    /** @param  list<string>  $objectIds */
    private function deleteStoredObjects(array $objectIds): void
    {
        foreach ($objectIds as $id) {
            try {
                $this->store->delete($id);
            } catch (Throwable) {
                // best-effort
            }
        }
    }
}
