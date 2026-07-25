<?php

namespace App\Services\Import;

use App\Contracts\SecureObjectStore;
use App\Enums\AdnDocumentType;
use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentArtifactQuality;
use App\Enums\DocumentDirection;
use App\Enums\DocumentKind;
use App\Enums\FiscalRole;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Enums\SignatureVerificationResult;
use App\Models\CteDocument;
use App\Models\CteEvent;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\DocumentInterest;
use App\Models\Establishment;
use App\Models\FiscalDocumentQuarantine;
use App\Models\NfeDocument;
use App\Services\Outbound\OutboundDeadlineSatisfactionService;
use App\Services\Sefaz\CteArtifactQualityClassifier;
use App\Services\Sefaz\CteReconciliationService;
use App\Services\Sefaz\CteXmlProjectionParser;
use App\Services\Sefaz\NfeXmlProjectionParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Ingestão de XML de saída (NF-e / NFC-e / CT-e) — vault + projeção OUT.
 * Não transmite à SEFAZ; apenas armazena XML já autorizado.
 * Não materializa A1/CSC.
 */
final class OutboundXmlIngestionService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly NfeXmlProjectionParser $parser,
        private readonly CteXmlProjectionParser $cteParser = new CteXmlProjectionParser,
        private readonly CteArtifactQualityClassifier $cteQuality = new CteArtifactQualityClassifier,
        private readonly SecureZipReader $zipReader = new SecureZipReader,
        private readonly ImportXmlClassifier $classifier = new ImportXmlClassifier,
        private readonly ImportFiscalValidator $fiscal = new ImportFiscalValidator,
        private readonly ImportMetrics $metrics = new ImportMetrics,
        private readonly CteReconciliationService $cteReconciliation = new CteReconciliationService,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{
     *   imported: int,
     *   skipped: int,
     *   errors: int,
     *   items: list<array{status: string, filename: string, access_key?: string, kind?: string, message?: string, sha256?: string}>
     * }
     */
    public function ingestUploads(int $officeId, ?int $clientId, array $files): array
    {
        $items = [];
        $imported = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName() ?: 'upload.bin';
            $bytes = @file_get_contents($file->getRealPath() ?: '');
            if ($bytes === false || $bytes === '') {
                $errors++;
                $items[] = ['status' => 'error', 'filename' => $name, 'message' => 'Arquivo vazio ou ilegível.'];

                continue;
            }

            if ($this->looksLikeZip($name, $bytes)) {
                $nested = $this->ingestZipBytes($officeId, $clientId, $bytes, $name);
                foreach ($nested as $row) {
                    $items[] = $row;
                    match ($row['status']) {
                        'imported' => $imported++,
                        'duplicate', 'skipped' => $skipped++,
                        default => $errors++,
                    };
                }

                continue;
            }

            $row = $this->ingestXmlBytes($officeId, $clientId, $bytes, $name);
            $items[] = $row;
            match ($row['status']) {
                'imported' => $imported++,
                'duplicate', 'skipped' => $skipped++,
                default => $errors++,
            };
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
            'items' => $items,
        ];
    }

    /**
     * @return list<array{status: string, filename: string, access_key?: string, kind?: string, message?: string, sha256?: string}>
     */
    private function ingestZipBytes(int $officeId, ?int $clientId, string $zipBytes, string $zipName): array
    {
        $started = hrtime(true);
        $compressed = strlen($zipBytes);
        try {
            $entries = $this->zipReader->extractXmlEntries($zipBytes, $zipName);
        } catch (RuntimeException $e) {
            $this->metrics->recordBackpressure($officeId, 'zip_rejected');

            return [['status' => 'error', 'filename' => $zipName, 'message' => $e->getMessage()]];
        }

        // Notas (procNFe) antes de eventos, independente da ordem no ZIP.
        $okEntries = [];
        $out = [];
        $uncompressed = 0;
        $rejected = 0;
        foreach ($entries as $entry) {
            if (($entry['status'] ?? '') !== 'ok') {
                $rejected++;
                $out[] = [
                    'status' => 'error',
                    'filename' => $entry['name'],
                    'message' => $entry['message'] ?? 'Entrada rejeitada.',
                    'result_code' => strtoupper((string) ($entry['status'] ?? 'ERROR')),
                ];

                continue;
            }
            $uncompressed += (int) ($entry['size'] ?? 0);
            $okEntries[] = $entry;
        }

        usort($okEntries, function (array $a, array $b): int {
            $ka = $this->classifier->classify($a['bytes'])['kind'] ?? '';
            $kb = $this->classifier->classify($b['bytes'])['kind'] ?? '';
            // Principais antes de eventos, independente da ordem no ZIP
            $rank = static fn (string $k): int => match ($k) {
                'procNFe', 'procCTe' => 0,
                'procEventoNFe', 'procEventoCTe' => 1,
                default => 2,
            };

            return $rank($ka) <=> $rank($kb);
        });

        foreach ($okEntries as $entry) {
            $row = $this->ingestXmlBytes($officeId, $clientId, $entry['bytes'], $entry['name']);
            unset($entry);
            $out[] = $row;
        }

        $this->metrics->recordZip(
            $officeId,
            count($entries),
            count($okEntries),
            $rejected,
            $compressed,
            $uncompressed,
            (hrtime(true) - $started) / 1_000_000,
        );

        if ($out === []) {
            return [['status' => 'error', 'filename' => $zipName, 'message' => 'ZIP sem arquivos XML.']];
        }

        return $out;
    }

    /**
     * @return array{status: string, filename: string, access_key?: string, kind?: string, message?: string, sha256?: string}
     */
    public function ingestXmlBytes(int $officeId, ?int $clientId, string $bytes, string $filename): array
    {
        $sha = hash('sha256', $bytes);

        $existing = DfeDocument::query()
            ->where('office_id', $officeId)
            ->where('sha256', $sha)
            ->first();

        if ($existing !== null) {
            return [
                'status' => 'duplicate',
                'filename' => $filename,
                'access_key' => $existing->access_key,
                'sha256' => $sha,
                'message' => 'SHA-256 já existe no escritório (idempotente).',
            ];
        }

        $classified = $this->classifier->classify($bytes);
        $kindClass = $classified['kind'];
        if (in_array($kindClass, ['invalid', 'unsupported', 'NFe_bare', 'resNFe', 'resCTe'], true)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => $classified['message'] ?? 'Artefato não elegível como documento de guarda.',
                'result_code' => match ($kindClass) {
                    'NFe_bare' => 'INVALID',
                    'resNFe', 'resCTe' => 'UNSUPPORTED',
                    'unsupported' => 'UNSUPPORTED',
                    default => 'INVALID',
                },
            ];
        }

        if ($kindClass === 'procEventoNFe') {
            return $this->ingestEventBytes($officeId, $bytes, $filename, $sha);
        }

        if ($kindClass === 'procEventoCTe') {
            return $this->ingestCteEventBytes($officeId, $bytes, $filename, $sha);
        }

        if ($kindClass === 'procCTe') {
            return $this->ingestCteBytes($officeId, $clientId, $bytes, $filename, $sha, $classified);
        }

        $modelHint = $classified['model'] ?? null;
        if ($modelHint !== null && ! in_array($modelHint, ['55', '65'], true)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => 'Modelo fora de 55/65/57.',
                'result_code' => 'UNSUPPORTED',
            ];
        }

        $family = $this->detectSchemaFamily($bytes);
        if ($family === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => 'XML não reconhecido como NF-e/NFC-e (procNFe/NFe).',
                'result_code' => 'INVALID',
            ];
        }

        $fiscal = $this->fiscal->validateProcNfe($bytes);
        if (! ($fiscal['ok'] ?? false)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => $fiscal['message'] ?? 'Validação fiscal falhou.',
                'result_code' => $fiscal['code'] ?? 'INVALID',
            ];
        }

        $parsed = $fiscal['parsed'] ?? $this->parser->parse($bytes, $family);
        $parseAlertExtra = $fiscal['parse_alert'] ?? null;
        $accessKey = $parsed['access_key'] ?? null;
        if (! $accessKey) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => 'Chave de acesso não extraída do XML.',
            ];
        }

        $model = (string) ($parsed['model'] ?? '55');
        $kind = $model === '65' ? DocumentKind::Nfce : DocumentKind::Nfe;
        $issuer = isset($parsed['issuer_cnpj']) ? strtoupper((string) $parsed['issuer_cnpj']) : null;

        // Estabelecimento emitente (vínculo de interesse / validação de cliente).
        $establishmentQuery = Establishment::query()
            ->whereHas('client', fn ($q) => $q->where('office_id', $officeId));
        if ($clientId !== null) {
            $establishmentQuery->where('client_id', $clientId);
        }
        if ($issuer === null || $issuer === '') {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'message' => 'CNPJ emitente ausente no XML.',
            ];
        }
        $establishment = (clone $establishmentQuery)->where('cnpj', $issuer)->first();
        if ($clientId !== null && $establishment === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'message' => 'Emitente do XML não corresponde à restrição de cliente (CLIENT_MISMATCH).',
                'result_code' => 'CLIENT_MISMATCH',
            ];
        }
        // Sem client_id: ainda tenta amarrar ao emitente do office (se existir).
        if ($establishment === null) {
            $establishment = Establishment::query()
                ->whereHas('client', fn ($q) => $q->where('office_id', $officeId))
                ->where('cnpj', $issuer)
                ->first();
        }
        if ($establishment === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'sha256' => $sha,
                'message' => 'Emitente não vinculado a estabelecimento ativo do escritório (UNMATCHED).',
                'result_code' => 'UNMATCHED',
            ];
        }

        // Não sobrescrever captura DistDFe de entrada com import de saída na mesma chave.
        $existingFull = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->first();
        if ($existingFull !== null) {
            $existingDir = $existingFull->direction?->value ?? null;
            $isImport = str_starts_with((string) $existingFull->schema_hint, 'import:');
            if (! $isImport && $existingDir === DocumentDirection::In->value) {
                return [
                    'status' => 'error',
                    'filename' => $filename,
                    'access_key' => $accessKey,
                    'message' => 'Já existe XML completo de entrada (DistDFe) para esta chave; import de saída recusado.',
                ];
            }
            if ($isImport || $existingDir === DocumentDirection::Out->value) {
                $existingDfe = $existingFull->document;
                if ($existingDfe && $existingDfe->sha256 === $sha) {
                    return [
                        'status' => 'duplicate',
                        'filename' => $filename,
                        'access_key' => $accessKey,
                        'sha256' => $sha,
                        'message' => 'SHA-256 já existe no escritório (idempotente).',
                    ];
                }
                if ($existingDfe && $existingDfe->sha256 !== $sha) {
                    // Mesma chave, bytes divergentes → quarentena; canônico permanece
                    $qObject = $this->store->put($bytes, [
                        'office_id' => $officeId,
                        'sha256' => $sha,
                        'kind' => 'import-quarantine',
                    ]);
                    FiscalDocumentQuarantine::query()->firstOrCreate(
                        [
                            'office_id' => $officeId,
                            'sha256' => $sha,
                            'source' => DocumentAcquisitionSource::ManualXml,
                            'nsu' => null,
                        ],
                        [
                            'vault_object_id' => $qObject,
                            'byte_size' => strlen($bytes),
                            'access_key' => $accessKey,
                            'issuer_cnpj' => $issuer,
                            'model' => $model,
                            'reason' => QuarantineReason::BytesDiverge,
                            'channel' => CaptureChannel::ImportXml,
                            'resolution_status' => QuarantineResolutionStatus::Open,
                        ]
                    );

                    return [
                        'status' => 'error',
                        'filename' => $filename,
                        'access_key' => $accessKey,
                        'sha256' => $sha,
                        'message' => 'Mesma chave com bytes divergentes — quarentena; canônico preservado.',
                        'result_code' => 'QUARANTINE_DIVERGE',
                    ];
                }
            }
        }

        $itemStarted = hrtime(true);
        try {
            DB::transaction(function () use (
                $officeId,
                $bytes,
                $sha,
                $accessKey,
                $parsed,
                $family,
                $model,
                $establishment,
                $parseAlertExtra,
                $issuer,
            ): void {
                $existingDfe = DfeDocument::query()
                    ->where('office_id', $officeId)
                    ->where('sha256', $sha)
                    ->first();
                if ($existingDfe !== null) {
                    $dfe = $existingDfe;
                } else {
                    $objectId = $this->store->put($bytes, [
                        'office_id' => $officeId,
                        'sha256' => $sha,
                    ]);
                    $alert = 'Importação manual de saída (não DistDFe).';
                    if (is_string($parseAlertExtra) && $parseAlertExtra !== '') {
                        $alert .= ' '.$parseAlertExtra;
                    }
                    $dfe = DfeDocument::query()->create([
                        'office_id' => $officeId,
                        'sha256' => $sha,
                        'document_type' => AdnDocumentType::Nfe,
                        'schema_version' => $family === 'procNFe' ? 'procNFe_v4.00.xsd' : 'NFe_import.xml',
                        'access_key' => $accessKey,
                        'vault_object_id' => $objectId,
                        'byte_size' => strlen($bytes),
                        'parse_status' => 'OK',
                        'parse_alert' => $alert,
                    ]);
                }

                NfeDocument::query()->updateOrCreate(
                    [
                        'office_id' => $officeId,
                        'access_key' => $accessKey,
                        'is_summary' => false,
                    ],
                    [
                        'dfe_document_id' => $dfe->id,
                        'number' => $parsed['number'] ?? null,
                        'series' => $parsed['series'] ?? null,
                        'model' => $model,
                        'issuer_cnpj' => $parsed['issuer_cnpj'] ?? null,
                        'issuer_name' => $parsed['issuer_name'] ?? null,
                        'recipient_cnpj' => $parsed['recipient_cnpj'] ?? null,
                        'recipient_name' => $parsed['recipient_name'] ?? null,
                        'fiscal_role' => FiscalRole::Issuer,
                        'direction' => DocumentDirection::Out,
                        'issued_at' => $parsed['issued_at'] ?? null,
                        'total_amount' => $parsed['total_amount'] ?? null,
                        'status' => $parsed['status'] ?? 'ACTIVE',
                        'official_status_code' => $parsed['official_status_code'] ?? null,
                        'manifestation_status' => null,
                        'schema_hint' => 'import:'.$family,
                    ]
                );

                // Interesse ISSUER/OUT sem NSU sintético — proveniência via document_acquisitions.
                DocumentInterest::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $dfe->id,
                        'establishment_id' => $establishment->id,
                        'fiscal_role' => FiscalRole::Issuer->value,
                        'channel' => CaptureChannel::ImportXml->value,
                    ],
                    [
                        'office_id' => $officeId,
                        'environment' => 'import',
                        'nsu' => null,
                        'direction' => DocumentDirection::Out->value,
                    ]
                );

                DocumentAcquisition::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $dfe->id,
                        'source' => DocumentAcquisitionSource::ManualXml->value,
                        'sha256' => $sha,
                    ],
                    [
                        'office_id' => $officeId,
                        'access_key' => $accessKey,
                        'channel' => CaptureChannel::ImportXml->value,
                        'artifact_quality' => DocumentArtifactQuality::Original,
                        'signature_result' => SignatureVerificationResult::Valid,
                        'is_canonical' => true,
                        'establishment_id' => $establishment->id,
                    ]
                );

                // Destinatário também cliente do office → interesse TAKER/IN no mesmo XML.
                $recipient = isset($parsed['recipient_cnpj'])
                    ? strtoupper((string) $parsed['recipient_cnpj'])
                    : null;
                if ($recipient !== null && $recipient !== '' && $recipient !== $issuer) {
                    $takerEstab = Establishment::query()
                        ->whereHas('client', fn ($q) => $q->where('office_id', $officeId))
                        ->where('cnpj', $recipient)
                        ->first();
                    if ($takerEstab !== null) {
                        DocumentInterest::query()->firstOrCreate(
                            [
                                'dfe_document_id' => $dfe->id,
                                'establishment_id' => $takerEstab->id,
                                'fiscal_role' => FiscalRole::Taker->value,
                                'channel' => CaptureChannel::ImportXml->value,
                            ],
                            [
                                'office_id' => $officeId,
                                'environment' => 'import',
                                'nsu' => null,
                                'direction' => DocumentDirection::In->value,
                            ]
                        );
                    }
                }
            });

            // Satisfaz prazo e cancela recoveries SVRS pendentes (fonte preferencial)
            try {
                app(OutboundDeadlineSatisfactionService::class)
                    ->markCapturedBySource($officeId, $accessKey, 'MANUAL_XML', $sha);
            } catch (\Throwable) {
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            // Corrida de unique (PostgreSQL 23505 / MySQL 23000) → idempotente
            if (str_contains($msg, 'duplicate') || str_contains($msg, 'Unique') || str_contains($msg, '23505') || str_contains($msg, '1062')) {
                $existing = DfeDocument::query()->where('office_id', $officeId)->where('sha256', $sha)->first();

                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $accessKey,
                    'sha256' => $sha,
                    'message' => 'Duplicata concorrente reconciliada (idempotente).',
                    'result_code' => 'DUPLICATE_RACE',
                    'dfe_document_id' => $existing?->id,
                ];
            }
            Log::warning('documents.import.persist_failed', [
                'office_id' => $officeId,
                'sha256' => $sha,
                'error' => class_basename($e),
            ]);

            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'sha256' => $sha,
                'message' => 'Falha ao persistir o XML importado.',
            ];
        }

        $this->metrics->recordItem(
            $officeId,
            'imported',
            strlen($bytes),
            (hrtime(true) - $itemStarted) / 1_000_000,
        );

        return [
            'status' => 'imported',
            'filename' => $filename,
            'access_key' => $accessKey,
            'kind' => $kind->value,
            'sha256' => $sha,
        ];
    }

    /**
     * Evento de cancelamento/CC-e: exige nota-pai já no catálogo (full).
     *
     * @return array{status: string, filename: string, access_key?: string, message?: string, sha256?: string, result_code?: string}
     */
    private function ingestEventBytes(int $officeId, string $bytes, string $filename, string $sha): array
    {
        $fiscal = $this->fiscal->validateProcEvento($bytes);
        if (! ($fiscal['ok'] ?? false)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => $fiscal['message'] ?? 'Evento inválido.',
                'result_code' => $fiscal['code'] ?? 'INVALID',
            ];
        }
        $parsed = $fiscal['parsed'] ?? [];
        $key = strtoupper((string) ($parsed['access_key'] ?? ''));

        $parent = NfeDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('is_summary', false)
            ->first();
        if ($parent === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $key,
                'sha256' => $sha,
                'message' => 'Evento órfão — importe a nota antes do evento.',
                'result_code' => 'EVENT_ORPHAN',
            ];
        }

        try {
            $objectId = $this->store->put($bytes, [
                'office_id' => $officeId,
                'sha256' => $sha,
                'kind' => 'import-event',
            ]);
            DfeDocument::query()->firstOrCreate(
                ['office_id' => $officeId, 'sha256' => $sha],
                [
                    'document_type' => AdnDocumentType::NfeEvent,
                    'schema_version' => 'procEventoNFe',
                    'access_key' => $key,
                    'vault_object_id' => $objectId,
                    'byte_size' => strlen($bytes),
                    'parse_status' => 'OK',
                    'parse_alert' => 'Evento importado (manual).',
                ]
            );
            if (! empty($parsed['is_cancel'])) {
                $parent->status = 'CANCELLED';
                $parent->save();
            }
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '23505') || str_contains(strtolower($msg), 'unique') || str_contains($msg, 'duplicate')) {
                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $key,
                    'sha256' => $sha,
                    'result_code' => 'DUPLICATE_RACE',
                ];
            }

            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $key,
                'sha256' => $sha,
                'message' => 'Falha ao persistir evento.',
            ];
        }

        return [
            'status' => 'imported',
            'filename' => $filename,
            'access_key' => $key,
            'sha256' => $sha,
            'kind' => 'EVENT',
            'result_code' => 'EVENT_IMPORTED',
        ];
    }

    /**
     * Import de cteProc modelo 57 — ISSUER/OUT por emit/CNPJ no office.
     *
     * @param  array{kind: string, model: ?string}  $classified
     * @return array{status: string, filename: string, access_key?: string, kind?: string, message?: string, sha256?: string, result_code?: string}
     */
    private function ingestCteBytes(
        int $officeId,
        ?int $clientId,
        string $bytes,
        string $filename,
        string $sha,
        array $classified,
    ): array {
        $modelHint = $classified['model'] ?? '57';
        if ($modelHint !== null && $modelHint !== '57') {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => 'Modelo CT-e '.$modelHint.' não projetado (apenas 57).',
                'result_code' => 'UNSUPPORTED',
            ];
        }

        $fiscal = $this->fiscal->validateProcCte($bytes);
        if (! ($fiscal['ok'] ?? false)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => $fiscal['message'] ?? 'Validação fiscal CT-e falhou.',
                'result_code' => $fiscal['code'] ?? 'INVALID',
            ];
        }

        $parsed = $fiscal['parsed'] ?? $this->cteParser->parse($bytes, 'procCTe');
        $accessKey = $parsed['access_key'] ?? null;
        if (! $accessKey) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => 'Chave CT-e não extraída do XML.',
            ];
        }

        $issuer = isset($parsed['issuer_cnpj']) ? strtoupper((string) $parsed['issuer_cnpj']) : null;
        if ($issuer === null || $issuer === '') {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'message' => 'CNPJ emitente ausente no CT-e.',
            ];
        }

        $establishmentQuery = Establishment::query()
            ->whereHas('client', fn ($q) => $q->where('office_id', $officeId));
        if ($clientId !== null) {
            $establishmentQuery->where('client_id', $clientId);
        }
        $establishment = (clone $establishmentQuery)->where('cnpj', $issuer)->first();
        if ($clientId !== null && $establishment === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'message' => 'Emitente do CT-e não corresponde à restrição de cliente (CLIENT_MISMATCH).',
                'result_code' => 'CLIENT_MISMATCH',
            ];
        }
        if ($establishment === null) {
            $establishment = Establishment::query()
                ->whereHas('client', fn ($q) => $q->where('office_id', $officeId))
                ->where('cnpj', $issuer)
                ->first();
        }
        if ($establishment === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'sha256' => $sha,
                'message' => 'Emitente CT-e não vinculado a estabelecimento do escritório (UNMATCHED).',
                'result_code' => 'UNMATCHED',
            ];
        }

        // Reconciliar com cópia autXML redigida existente
        $existingCte = CteDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $accessKey)
            ->where('is_summary', false)
            ->first();
        if ($existingCte !== null) {
            $existingDfe = $existingCte->document;
            if ($existingDfe && $existingDfe->sha256 === $sha) {
                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $accessKey,
                    'sha256' => $sha,
                    'kind' => DocumentKind::Cte->value,
                    'message' => 'SHA-256 já existe no escritório (idempotente).',
                ];
            }
            if ($existingDfe && $existingDfe->sha256 !== $sha) {
                // Preferir original importado como canônico se o existente for autXML redigido
                $existingAcq = DocumentAcquisition::query()
                    ->where('dfe_document_id', $existingDfe->id)
                    ->where('is_canonical', true)
                    ->first();
                $existingQuality = $existingAcq?->artifact_quality;
                if ($existingQuality === DocumentArtifactQuality::Original) {
                    $qObject = $this->store->put($bytes, [
                        'office_id' => $officeId,
                        'sha256' => $sha,
                        'kind' => 'import-quarantine',
                    ]);
                    FiscalDocumentQuarantine::query()->firstOrCreate(
                        [
                            'office_id' => $officeId,
                            'sha256' => $sha,
                            'source' => DocumentAcquisitionSource::ManualXml,
                            'nsu' => null,
                        ],
                        [
                            'vault_object_id' => $qObject,
                            'byte_size' => strlen($bytes),
                            'access_key' => $accessKey,
                            'issuer_cnpj' => $issuer,
                            'model' => '57',
                            'reason' => QuarantineReason::BytesDiverge,
                            'channel' => CaptureChannel::ImportXml,
                            'resolution_status' => QuarantineResolutionStatus::Open,
                        ]
                    );

                    return [
                        'status' => 'error',
                        'filename' => $filename,
                        'access_key' => $accessKey,
                        'sha256' => $sha,
                        'message' => 'Mesma chave CT-e com bytes divergentes — quarentena; canônico preservado.',
                        'result_code' => 'QUARANTINE_DIVERGE',
                    ];
                }
                // Promove original importado; rebaixa autXML
                if ($existingAcq) {
                    $existingAcq->update(['is_canonical' => false]);
                }
            }
        }

        $quality = $this->cteQuality->classify($parsed, false, true, false);
        $source = str_ends_with(strtolower($filename), '.zip')
            ? DocumentAcquisitionSource::ManualZip
            : DocumentAcquisitionSource::ManualXml;

        try {
            DB::transaction(function () use (
                $officeId,
                $bytes,
                $sha,
                $accessKey,
                $parsed,
                $establishment,
                $fiscal,
                $quality,
                $source,
            ): void {
                $existingDfe = DfeDocument::query()
                    ->where('office_id', $officeId)
                    ->where('sha256', $sha)
                    ->first();
                if ($existingDfe !== null) {
                    $dfe = $existingDfe;
                } else {
                    $objectId = $this->store->put($bytes, [
                        'office_id' => $officeId,
                        'sha256' => $sha,
                    ]);
                    $alert = 'Importação manual de CT-e (não DistDFe).';
                    if (! empty($fiscal['parse_alert'])) {
                        $alert .= ' '.$fiscal['parse_alert'];
                    }
                    $dfe = DfeDocument::query()->create([
                        'office_id' => $officeId,
                        'sha256' => $sha,
                        'document_type' => AdnDocumentType::Cte,
                        'schema_version' => 'procCTe_v'.($parsed['schema_version'] ?? '4.00').'.xsd',
                        'access_key' => $accessKey,
                        'vault_object_id' => $objectId,
                        'byte_size' => strlen($bytes),
                        'parse_status' => 'OK',
                        'parse_alert' => $alert,
                    ]);
                }

                CteDocument::query()->updateOrCreate(
                    [
                        'office_id' => $officeId,
                        'access_key' => $accessKey,
                        'is_summary' => false,
                    ],
                    [
                        'dfe_document_id' => $dfe->id,
                        'number' => $parsed['number'] ?? null,
                        'series' => $parsed['series'] ?? null,
                        'model' => '57',
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
                        'fiscal_role' => FiscalRole::Issuer,
                        'direction' => DocumentDirection::Out,
                        'issued_at' => $parsed['issued_at'] ?? null,
                        'total_amount' => $parsed['total_amount'] ?? null,
                        'status' => $parsed['status'] ?? 'ACTIVE',
                        'official_status_code' => $parsed['official_status_code'] ?? null,
                        'protocol_number' => $parsed['protocol_number'] ?? null,
                        'schema_hint' => 'import:procCTe',
                        'schema_version' => $parsed['schema_version'] ?? null,
                    ]
                );

                // Interesse ISSUER/OUT sem NSU (null) — alinhado ao import NF-e; múltiplos
                // NULL não colidem na unique (estab, env, channel, nsu, fiscal_role).
                DocumentInterest::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $dfe->id,
                        'establishment_id' => $establishment->id,
                        'fiscal_role' => FiscalRole::Issuer->value,
                        'channel' => CaptureChannel::ImportXml->value,
                    ],
                    [
                        'office_id' => $officeId,
                        'environment' => 'import',
                        'nsu' => null,
                        'direction' => DocumentDirection::Out->value,
                    ]
                );

                DocumentAcquisition::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $dfe->id,
                        'source' => $source->value,
                        'sha256' => $sha,
                    ],
                    [
                        'office_id' => $officeId,
                        'access_key' => $accessKey,
                        'channel' => CaptureChannel::ImportXml->value,
                        'artifact_quality' => $quality['quality'],
                        'signature_result' => $quality['signature'],
                        'is_canonical' => true,
                        'establishment_id' => $establishment->id,
                    ]
                );
            });
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'duplicate') || str_contains($msg, 'Unique') || str_contains($msg, '23505') || str_contains($msg, '1062')) {
                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $accessKey,
                    'sha256' => $sha,
                    'kind' => DocumentKind::Cte->value,
                    'message' => 'Duplicata concorrente reconciliada (idempotente).',
                    'result_code' => 'DUPLICATE_RACE',
                ];
            }
            Log::warning('documents.import.cte_persist_failed', [
                'office_id' => $officeId,
                'sha256' => $sha,
                'error' => class_basename($e).': '.mb_substr($msg, 0, 200),
            ]);

            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $accessKey,
                'sha256' => $sha,
                'message' => 'Falha ao persistir o CT-e importado.',
            ];
        }

        $this->cteReconciliation->reconcileDocument($officeId, $accessKey);

        return [
            'status' => 'imported',
            'filename' => $filename,
            'access_key' => $accessKey,
            'kind' => DocumentKind::Cte->value,
            'sha256' => $sha,
        ];
    }

    /**
     * @return array{status: string, filename: string, access_key?: string, message?: string, sha256?: string, result_code?: string, kind?: string}
     */
    private function ingestCteEventBytes(int $officeId, string $bytes, string $filename, string $sha): array
    {
        $fiscal = $this->fiscal->validateProcEventoCte($bytes);
        if (! ($fiscal['ok'] ?? false)) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'sha256' => $sha,
                'message' => $fiscal['message'] ?? 'Evento CT-e inválido.',
                'result_code' => $fiscal['code'] ?? 'INVALID',
            ];
        }
        $parsed = $fiscal['parsed'] ?? [];
        $key = strtoupper((string) ($parsed['access_key'] ?? ''));

        $parent = CteDocument::query()
            ->where('office_id', $officeId)
            ->where('access_key', $key)
            ->where('is_summary', false)
            ->first();
        if ($parent === null) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $key,
                'sha256' => $sha,
                'message' => 'Evento CT-e órfão — importe o CT-e principal antes do evento.',
                'result_code' => 'EVENT_ORPHAN',
            ];
        }

        try {
            $objectId = $this->store->put($bytes, [
                'office_id' => $officeId,
                'sha256' => $sha,
                'kind' => 'import-event-cte',
            ]);
            $dfe = DfeDocument::query()->firstOrCreate(
                ['office_id' => $officeId, 'sha256' => $sha],
                [
                    'document_type' => AdnDocumentType::Unknown,
                    'schema_version' => 'procEventoCTe',
                    'access_key' => $key,
                    'vault_object_id' => $objectId,
                    'byte_size' => strlen($bytes),
                    'parse_status' => 'OK',
                    'parse_alert' => 'Evento CT-e importado (manual).',
                ]
            );
            if (! empty($parsed['is_cancel'])) {
                $parent->status = 'CANCELLED';
                $parent->save();
            }
            CteEvent::query()->updateOrCreate(
                [
                    'office_id' => $officeId,
                    'access_key' => $key,
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
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, '23505') || str_contains(strtolower($msg), 'unique') || str_contains($msg, 'duplicate')) {
                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $key,
                    'sha256' => $sha,
                    'result_code' => 'DUPLICATE_RACE',
                ];
            }

            return [
                'status' => 'error',
                'filename' => $filename,
                'access_key' => $key,
                'sha256' => $sha,
                'message' => 'Falha ao persistir evento CT-e.',
            ];
        }

        $this->cteReconciliation->reconcileDocument($officeId, $key);

        return [
            'status' => 'imported',
            'filename' => $filename,
            'access_key' => $key,
            'sha256' => $sha,
            'kind' => 'EVENT',
            'result_code' => 'EVENT_IMPORTED',
        ];
    }

    private function looksLikeZip(string $name, string $bytes): bool
    {
        if (preg_match('/\.zip$/i', $name)) {
            return true;
        }

        return str_starts_with($bytes, "PK\x03\x04") || str_starts_with($bytes, "PK\x05\x06");
    }

    private function detectSchemaFamily(string $xml): ?string
    {
        if (! str_contains($xml, '<') || ! str_contains(strtolower($xml), 'nfe')) {
            return null;
        }
        if (preg_match('/<(?:\w+:)?nfeProc\b/i', $xml) || preg_match('/local-name\(\)\s*=\s*[\'"]nfeProc/i', $xml)) {
            return 'procNFe';
        }
        if (preg_match('/<(?:\w+:)?NFe\b/i', $xml)) {
            return 'procNFe'; // parser de projeção cobre NFe embutida via mesmos XPaths
        }
        if (preg_match('/<(?:\w+:)?resNFe\b/i', $xml)) {
            return 'resNFe';
        }

        return null;
    }
}
