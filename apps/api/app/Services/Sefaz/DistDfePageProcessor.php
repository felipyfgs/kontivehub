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
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\ChannelSyncCursor;
use App\Models\DfeDocument;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\NfeDocument;
use App\Models\NfeEvent;
use App\Services\Adn\DocumentDecoder;
use App\Services\FiscalDataModel\DocumentAcquisitionRecorder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persiste lote DistDFe e só então avança NSU do channel_sync_cursors.
 */
final class DistDfePageProcessor
{
    public function __construct(
        private readonly DocumentDecoder $decoder,
        private readonly NfeXmlProjectionParser $parser,
        private readonly SecureObjectStore $store,
        private readonly DistDfeResponseParser $schemaHelper,
        private readonly AutoCienciaScheduler $autoCiencia,
        private readonly DocumentAcquisitionRecorder $acquisitions,
    ) {}

    /**
     * @return array{documents: int, advanced_to: int}
     */
    public function process(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfePageDto $page,
    ): array {
        if ($page->isAbuse()) {
            $cursor->status = SyncCursorStatus::Blocked;
            $cursor->last_cstat = $page->cStat;
            $cursor->last_xmotivo = mb_substr($page->xMotivo, 0, 255);
            $cursor->last_error = 'Consumo indevido SEFAZ (cStat 656). Aguardar ≥1h.';
            $cursor->next_sync_at = now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
            $cursor->save();

            return ['documents' => 0, 'advanced_to' => (int) $cursor->last_nsu];
        }

        $storedObjectIds = [];
        /** @var list<string> $summaryKeysForCiencia */
        $summaryKeysForCiencia = [];

        try {
            $result = DB::transaction(function () use ($cursor, $establishment, $page, &$storedObjectIds, &$summaryKeysForCiencia) {
                $cursor = ChannelSyncCursor::query()->whereKey($cursor->id)->lockForUpdate()->firstOrFail();
                $persisted = 0;

                foreach ($page->documents as $doc) {
                    $this->persistDocument($cursor, $establishment, $doc, $storedObjectIds, $summaryKeysForCiencia);
                    $persisted++;
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
                $cursor->last_error = null;
                $cursor->consecutive_decode_failures = 0;

                if ($page->isEndOfQueue()) {
                    $cursor->status = SyncCursorStatus::Idle;
                    $cursor->next_sync_at = now()->addHours((float) config('sefaz.quiet_hours_after_empty', 1));
                }

                $cursor->save();

                return ['documents' => $persisted, 'advanced_to' => $newNsu];
            });

            // Fora da transação: SEFAZ RecepcaoEvento não pode travar o commit do lote DistDFe.
            $this->autoCiencia->enqueueForKeys((int) $cursor->office_id, $summaryKeysForCiencia);

            return $result;
        } catch (Throwable $e) {
            $this->deleteStoredObjects($storedObjectIds);
            if ($e instanceof DocumentDecodeException) {
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
            }
            throw $e;
        }
    }

    /**
     * Persiste documentos de uma página DistDFe **sem** avançar last_nsu.
     * Uso: reconsulta pontual (consChNFe) após manifestação.
     *
     * @return array{documents: int}
     */
    public function ingestDocuments(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfePageDto $page,
    ): array {
        if ($page->isAbuse() || $page->documents === []) {
            return ['documents' => 0];
        }

        $storedObjectIds = [];
        /** @var list<string> $summaryKeysForCiencia */
        $summaryKeysForCiencia = [];

        try {
            $result = DB::transaction(function () use ($cursor, $establishment, $page, &$storedObjectIds, &$summaryKeysForCiencia) {
                $persisted = 0;
                foreach ($page->documents as $doc) {
                    $this->persistDocument($cursor, $establishment, $doc, $storedObjectIds, $summaryKeysForCiencia);
                    $persisted++;
                }

                return ['documents' => $persisted];
            });

            $this->autoCiencia->enqueueForKeys((int) $cursor->office_id, $summaryKeysForCiencia);

            return $result;
        } catch (Throwable $e) {
            $this->deleteStoredObjects($storedObjectIds);
            throw $e;
        }
    }

    /**
     * @param  list<string>  $storedObjectIds
     * @param  list<string>  $summaryKeysForCiencia
     */
    private function persistDocument(
        ChannelSyncCursor $cursor,
        Establishment $establishment,
        DistDfeDocumentDto $doc,
        array &$storedObjectIds,
        array &$summaryKeysForCiencia = [],
    ): void {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $family = $doc->schemaFamily !== 'unknown'
            ? $doc->schemaFamily
            : $this->schemaHelper->schemaFamily($doc->schema);

        $parsed = $this->parser->parse($decoded['bytes'], $family);
        $accessKey = $parsed['access_key'] ?? null;
        $isEvent = in_array($family, ['procEventoNFe', 'resEvento'], true);
        $docType = $isEvent ? AdnDocumentType::NfeEvent : AdnDocumentType::Nfe;

        $dfe = $this->findOrStoreDocument(
            $cursor,
            $doc,
            $decoded,
            $docType,
            $accessKey,
            $storedObjectIds,
        );

        $interest = DocumentInterest::query()->firstOrCreate(
            [
                'establishment_id' => $establishment->id,
                'environment' => $cursor->environment,
                'channel' => CaptureChannel::NfeDistDfe->value,
                'nsu' => $doc->nsu,
            ],
            [
                'office_id' => $cursor->office_id,
                'dfe_document_id' => $dfe->id,
                'fiscal_role' => FiscalRole::Taker->value,
            ]
        );

        $this->acquisitions->record(
            document: $dfe,
            source: DocumentAcquisitionSource::NfeDistDfe,
            sha256: $decoded['sha256'],
            establishmentId: $establishment->id,
            nsu: $doc->nsu,
            channel: CaptureChannel::NfeDistDfe->value,
            interest: $interest,
        );

        if ($isEvent && $accessKey) {
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
                NfeDocument::query()
                    ->where('office_id', $cursor->office_id)
                    ->where('access_key', $accessKey)
                    ->where('is_summary', false)
                    ->update(['status' => 'CANCELLED']);
            }

            return;
        }

        if (! $accessKey) {
            return;
        }

        $isSummary = (bool) ($parsed['is_summary'] ?? false);
        $existing = NfeDocument::query()
            ->where('office_id', $cursor->office_id)
            ->where('access_key', $accessKey)
            ->where('is_summary', $isSummary)
            ->first();

        // Não rebaixar CIENCIA_REGISTRADA / conclusiva ao reprocessar o mesmo resumo.
        $manifestationStatus = $parsed['manifestation_status'] ?? null;
        if ($manifestationStatus === null) {
            if ($isSummary) {
                $current = (string) ($existing?->manifestation_status ?? '');
                $manifestationStatus = in_array($current, [
                    'CIENCIA_REGISTRADA', 'CONFIRMADA', 'DESCONHECIDA', 'NAO_REALIZADA',
                ], true)
                    ? $current
                    : 'PENDING_MANIFESTATION';
            }
        }

        NfeDocument::query()->updateOrCreate(
            [
                'office_id' => $cursor->office_id,
                'access_key' => $accessKey,
                'is_summary' => $isSummary,
            ],
            [
                'dfe_document_id' => $dfe->id,
                'number' => $parsed['number'] ?? null,
                'series' => $parsed['series'] ?? null,
                'model' => $parsed['model'] ?? '55',
                'issuer_cnpj' => $parsed['issuer_cnpj'] ?? null,
                'issuer_name' => $parsed['issuer_name'] ?? null,
                'recipient_cnpj' => $parsed['recipient_cnpj'] ?? null,
                'recipient_name' => $parsed['recipient_name'] ?? null,
                'fiscal_role' => FiscalRole::Taker,
                // DistDFe de interesse (não emissão própria) → entrada
                'direction' => DocumentDirection::In,
                'issued_at' => $parsed['issued_at'] ?? null,
                'total_amount' => $parsed['total_amount'] ?? null,
                'status' => $parsed['status'] ?? 'UNKNOWN',
                'official_status_code' => $parsed['official_status_code'] ?? null,
                'manifestation_status' => $manifestationStatus,
                'schema_hint' => $doc->schema,
            ]
        );

        if ($isSummary) {
            $hasFull = NfeDocument::query()
                ->where('office_id', $cursor->office_id)
                ->where('access_key', $accessKey)
                ->where('is_summary', false)
                ->exists();
            if (! $hasFull) {
                $summaryKeysForCiencia[] = $accessKey;
            }
        }
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
                'parse_alert' => $accessKey ? null : 'Chave de acesso não extraída; XML preservado.',
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
