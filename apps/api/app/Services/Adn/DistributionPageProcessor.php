<?php

namespace App\Services\Adn;

use App\Contracts\SecureObjectStore;
use App\Domain\Adn\DistributionDocumentDto;
use App\Domain\Adn\DistributionPageDto;
use App\Enums\AdnDocumentType;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentDirection;
use App\Enums\FiscalRole;
use App\Enums\SyncCursorStatus;
use App\Exceptions\Adn\AdnInvalidResponseException;
use App\Exceptions\Adn\DocumentDecodeException;
use App\Models\DfeDocument;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\NfseDocument;
use App\Models\NfseEvent;
use App\Models\SyncCursor;
use App\Services\FiscalDataModel\DocumentAcquisitionRecorder;
use App\Support\NfseNoteStatus;
use Illuminate\Support\Facades\DB;
use Throwable;

final class DistributionPageProcessor
{
    /** Schemas oficiais reconhecidos pelo parser versionado do MVP. */
    private const KNOWN_SCHEMAS = [
        'NFSe_v1.00.xsd',
        'NFSe_v1.01.xsd',
        'evento_v1.00.xsd',
        'evento_v1.01.xsd',
        'retDistDFeInt_v1.00.xsd',
    ];

    public function __construct(
        private readonly DocumentDecoder $decoder,
        private readonly NfseXmlParser $parser,
        private readonly SecureObjectStore $store,
        private readonly DocumentAcquisitionRecorder $acquisitions,
    ) {}

    /**
     * Persiste a página inteira e só então avança o NSU.
     *
     * @return array{documents: int, advanced_to: int}
     */
    public function process(SyncCursor $cursor, Establishment $establishment, DistributionPageDto $page): array
    {
        try {
            return DB::transaction(function () use ($cursor, $establishment, $page) {
                $cursor = SyncCursor::query()->whereKey($cursor->id)->lockForUpdate()->firstOrFail();
                $this->assertPageCanAdvance($cursor, $page);
                $persisted = 0;

                foreach ($page->documents as $doc) {
                    $this->persistDocument($cursor, $establishment, $doc);
                    $persisted++;
                }

                // NENHUM_DOCUMENTO_LOCALIZADO preserva o cursor; DOCUMENTOS_LOCALIZADOS avança ao maior NSU do lote.
                $newNsu = $page->status === HttpAdnContributorClient::STATUS_NONE_FOUND
                    ? $cursor->last_nsu
                    : $page->ultimoNsu;
                $cursor->last_nsu = $newNsu;
                $cursor->last_success_at = now();
                $cursor->last_error = null;
                $cursor->consecutive_decode_failures = 0;
                $cursor->save();

                return ['documents' => $persisted, 'advanced_to' => $newNsu];
            });
        } catch (Throwable $e) {
            if ($e instanceof DocumentDecodeException) {
                // Fora da transação: não avança NSU e registra falha de decode
                $fresh = SyncCursor::query()->whereKey($cursor->id)->first();
                if ($fresh) {
                    $fresh->consecutive_decode_failures++;
                    $fresh->last_error = $e->getMessage();
                    if ($fresh->consecutive_decode_failures >= (int) config('adn.decode_failure_threshold', 5)) {
                        $fresh->status = SyncCursorStatus::Blocked;
                    }
                    $fresh->save();
                }
            }
            throw $e;
        }
    }

    private function assertPageCanAdvance(SyncCursor $cursor, DistributionPageDto $page): void
    {
        if ($page->status === HttpAdnContributorClient::STATUS_NONE_FOUND) {
            if ($page->documents !== [] || $page->hasMore) {
                throw new AdnInvalidResponseException;
            }

            return;
        }

        if ($page->status !== HttpAdnContributorClient::STATUS_DOCUMENTS_FOUND
            || $page->documents === []
            || $page->ultimoNsu <= $cursor->last_nsu
            || $page->maxNsu < $page->ultimoNsu) {
            throw new AdnInvalidResponseException;
        }

        $nsus = [];
        foreach ($page->documents as $document) {
            if (! $document instanceof DistributionDocumentDto
                || $document->nsu <= $cursor->last_nsu
                || $document->nsu > $page->ultimoNsu
                || $document->schema === ''
                || $document->contentBase64 === '') {
                throw new AdnInvalidResponseException;
            }

            $nsus[] = $document->nsu;
        }

        if (count($nsus) !== count(array_unique($nsus)) || max($nsus) !== $page->ultimoNsu) {
            throw new AdnInvalidResponseException;
        }
    }

    private function persistDocument(SyncCursor $cursor, Establishment $establishment, DistributionDocumentDto $doc): void
    {
        $decoded = $this->decoder->decodeBase64Gzip($doc->contentBase64);
        $bytes = $decoded['bytes'];
        $sha = $decoded['sha256'];

        $existing = DfeDocument::query()
            ->where('tenant_id', $cursor->tenant_id)
            ->where('sha256', $sha)
            ->first();

        if ($existing === null) {
            $objectId = $this->store->put($bytes, [
                'tenant_id' => $cursor->tenant_id,
                'sha256' => $sha,
            ]);

            $parseStatus = 'OK';
            $parseAlert = null;
            $accessKey = null;
            $fiscalRole = null;
            $parsed = null;

            if ($doc->type === AdnDocumentType::Nfse || $doc->type === AdnDocumentType::Unknown) {
                $parsed = $this->parser->parseNote($bytes);
                [$accessKey, $parseStatus, $parseAlert] = $this->resolveAccessKeyAndParseStatus($parsed, $doc->accessKey);
                $fiscalRole = ($parsed['fiscal_role_for'])($establishment->cnpj);
            } elseif ($doc->type === AdnDocumentType::Event) {
                $parsed = $this->parser->parseEvent($bytes);
                [$accessKey, $parseStatus, $parseAlert] = $this->resolveAccessKeyAndParseStatus($parsed, $doc->accessKey);
            }

            // XML bem-formado com XSD/schema desconhecido: preserva bytes, marca REVIEW, não bloqueia cursor.
            if ($parseStatus !== 'FAILED' && ! $this->isKnownSchema($doc->schema)) {
                $parseStatus = 'REVIEW';
                $parseAlert = trim(($parseAlert ? $parseAlert.' ' : '').'Schema/XSD desconhecido: '.$doc->schema.'; XML preservado.');
            }

            $existing = DfeDocument::query()->create([
                'tenant_id' => $cursor->tenant_id,
                'sha256' => $sha,
                'document_type' => $doc->type,
                'schema_version' => $doc->schema,
                'access_key' => $accessKey,
                'vault_object_id' => $objectId,
                'byte_size' => strlen($bytes),
                'parse_status' => $parseStatus,
                'parse_alert' => $parseAlert,
            ]);

            if ($doc->type === AdnDocumentType::Nfse && isset($parsed) && is_array($parsed) && $accessKey) {
                NfseDocument::query()->firstOrCreate(
                    [
                        'tenant_id' => $cursor->tenant_id,
                        'access_key' => $accessKey,
                    ],
                    $this->noteProjectionAttributes($existing->id, $parsed, $fiscalRole),
                );
            }

            if ($doc->type === AdnDocumentType::Event && isset($parsed) && is_array($parsed)) {
                $eventKey = $accessKey ?? '';
                NfseEvent::query()->create([
                    'tenant_id' => $cursor->tenant_id,
                    'dfe_document_id' => $existing->id,
                    'access_key' => $eventKey,
                    'event_type' => $parsed['event_type'] ?? null,
                    'event_at' => $parsed['event_at'] ?? null,
                    'status' => $parsed['status'] ?? null,
                ]);

                $this->applyDerivedNoteStatus(
                    tenantId: (int) $cursor->tenant_id,
                    accessKey: $eventKey,
                    eventType: $parsed['event_type'] ?? null,
                );
            }
        } else {
            // Papel fiscal é por estabelecimento (interesse), não o da nota original.
            $fiscalRole = $this->fiscalRoleForEstablishment($existing, $establishment);
        }

        // Idempotência: (establishment, env, nsu) e no máximo um vínculo documento↔estabelecimento.
        $byNsu = DocumentInterest::query()
            ->where('establishment_id', $establishment->id)
            ->where('environment', $cursor->environment)
            ->where('nsu', $doc->nsu)
            ->first();

        $interest = $byNsu;
        if ($interest === null) {
            $byDocument = DocumentInterest::query()
                ->where('dfe_document_id', $existing->id)
                ->where('establishment_id', $establishment->id)
                ->first();

            if ($byDocument === null) {
                $interest = DocumentInterest::query()->create([
                    'establishment_id' => $establishment->id,
                    'environment' => $cursor->environment,
                    'nsu' => $doc->nsu,
                    'tenant_id' => $cursor->tenant_id,
                    'dfe_document_id' => $existing->id,
                    'fiscal_role' => $fiscalRole ?? null,
                    'channel' => 'NFSE_ADN',
                ]);
            } else {
                $interest = $byDocument;
            }
        }

        // Aquisição na mesma transação da página (NSU só avança após commit do process()).
        $this->acquisitions->record(
            document: $existing,
            source: DocumentAcquisitionSource::Adn,
            sha256: $sha,
            establishmentId: $establishment->id,
            nsu: $doc->nsu,
            channel: 'NFSE_ADN',
            interest: $interest,
        );
    }

    private function fiscalRoleForEstablishment(DfeDocument $document, Establishment $establishment): ?FiscalRole
    {
        $nfseDocument = $document->nfseDocument;
        if ($nfseDocument === null) {
            return null;
        }

        $cnpj = strtoupper($establishment->cnpj);

        if ($nfseDocument->issuer_cnpj === $cnpj) {
            return FiscalRole::Issuer;
        }
        if ($nfseDocument->taker_cnpj === $cnpj) {
            return FiscalRole::Taker;
        }
        if ($nfseDocument->intermediary_cnpj === $cnpj) {
            return FiscalRole::Intermediary;
        }

        return null;
    }

    private function isKnownSchema(string $schema): bool
    {
        $base = basename(str_replace('\\', '/', $schema));

        return in_array($base, self::KNOWN_SCHEMAS, true);
    }

    /**
     * Eventos de cancelamento/substituição atualizam a projeção da nota (sem apagar XML).
     *
     * @see NfseNoteStatus::fromEventType
     */
    private function applyDerivedNoteStatus(int $tenantId, string $accessKey, ?string $eventType): void
    {
        if ($accessKey === '' || $eventType === null) {
            return;
        }

        $status = NfseNoteStatus::fromEventType($eventType);
        if ($status === null) {
            return;
        }

        NfseDocument::query()
            ->where('tenant_id', $tenantId)
            ->where('access_key', $accessKey)
            ->update(['status' => $status]);
    }

    /**
     * Chave do envelope só preenche identidade; nunca promove parse FAILED → OK.
     *
     * @param  array{access_key?: ?string, parse_status: string, parse_alert?: ?string}  $parsed
     * @return array{0: ?string, 1: string, 2: ?string}
     */
    private function resolveAccessKeyAndParseStatus(array $parsed, ?string $envelopeAccessKey): array
    {
        $envelopeKey = $envelopeAccessKey !== null && $envelopeAccessKey !== ''
            ? strtoupper(trim($envelopeAccessKey))
            : null;
        $accessKey = $parsed['access_key'] ?? $envelopeKey;
        $parseStatus = $parsed['parse_status'];
        $parseAlert = $parsed['parse_alert'] ?? null;

        if (($parsed['access_key'] ?? null) === null && $envelopeKey !== null && $parseStatus !== 'FAILED') {
            // REVIEW típico por chave ausente no XML: envelope resolve a identidade.
            $parseStatus = 'OK';
            $parseAlert = null;
        }

        return [$accessKey, $parseStatus, $parseAlert];
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @return array<string, mixed>
     */
    private function noteProjectionAttributes(int $dfeDocumentId, array $parsed, ?FiscalRole $fiscalRole): array
    {
        return [
            'dfe_document_id' => $dfeDocumentId,
            'number' => $parsed['number'] ?? null,
            'issuer_cnpj' => $parsed['issuer_cnpj'] ?? null,
            'issuer_name' => $parsed['issuer_name'] ?? null,
            'taker_cnpj' => $parsed['taker_cnpj'] ?? null,
            'taker_name' => $parsed['taker_name'] ?? null,
            'intermediary_cnpj' => $parsed['intermediary_cnpj'] ?? null,
            'intermediary_name' => $parsed['intermediary_name'] ?? null,
            'fiscal_role' => $fiscalRole,
            'direction' => DocumentDirection::fromFiscalRole($fiscalRole),
            'competence' => $parsed['competence'] ?? null,
            'issued_at' => $parsed['issued_at'] ?? null,
            'service_amount' => $parsed['service_amount'] ?? null,
            'issue_location' => $parsed['issue_location'] ?? null,
            'service_location' => $parsed['service_location'] ?? null,
            'status' => $parsed['status'] ?? 'ACTIVE',
            'official_status_code' => $parsed['official_status_code'] ?? null,
        ];
    }
}
