<?php

namespace App\Services\Outbound;

use App\Contracts\SecureObjectStore;
use App\Enums\AdnDocumentType;
use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentDirection;
use App\Enums\DocumentPurpose;
use App\Enums\FiscalRole;
use App\Enums\OutboundNumberStatus;
use App\Enums\OutboundRetrievalStatus;
use App\Models\DfeDocument;
use App\Models\DocumentAcquisition;
use App\Models\Establishment;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\NfeDocument;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundNumberState;
use App\Services\Audit\AuditLogger;
use App\Services\Sefaz\NfeXmlProjectionParser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Ingestão transacional de pacote oficial MA (ZIP/XML) — vault + projeções + acquisitions.
 */
final class MaOfficialPackageIngestionService
{
    private const MAX_ZIP_BYTES = 50 * 1024 * 1024;

    private const MAX_FILES = 500;

    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly NfeXmlProjectionParser $parser,
        private readonly MaOfficialPackageValidator $validator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{
     *   imported: int,
     *   skipped: int,
     *   quarantined: int,
     *   errors: int,
     *   items: list<array<string, mixed>>
     * }
     */
    public function ingest(
        OutboundCaptureProfile $profile,
        Establishment $establishment,
        array $files,
        int $userId,
        ?MaOutboundRetrievalRequest $request = null,
    ): array {
        $items = [];
        $imported = $skipped = $quarantined = $errors = 0;

        foreach ($files as $file) {
            $name = $file->getClientOriginalName() ?: 'upload.bin';
            $bytes = @file_get_contents($file->getRealPath() ?: '');
            if ($bytes === false || $bytes === '') {
                $errors++;
                $items[] = ['status' => 'error', 'filename' => $name, 'message' => 'Arquivo vazio.'];

                continue;
            }

            if ($this->looksLikeZip($name, $bytes)) {
                if (strlen($bytes) > self::MAX_ZIP_BYTES) {
                    $errors++;
                    $items[] = ['status' => 'error', 'filename' => $name, 'message' => 'ZIP excede limite.'];

                    continue;
                }
                foreach ($this->extractZipXmls($bytes, $name) as $entry) {
                    $row = $this->ingestOneXml(
                        $profile,
                        $establishment,
                        $entry['bytes'],
                        $entry['filename'],
                        $request,
                    );
                    $items[] = $row;
                    match ($row['status']) {
                        'imported' => $imported++,
                        'duplicate', 'skipped' => $skipped++,
                        'quarantined' => $quarantined++,
                        default => $errors++,
                    };
                }

                continue;
            }

            $row = $this->ingestOneXml($profile, $establishment, $bytes, $name, $request);
            $items[] = $row;
            match ($row['status']) {
                'imported' => $imported++,
                'duplicate', 'skipped' => $skipped++,
                'quarantined' => $quarantined++,
                default => $errors++,
            };
        }

        if ($request !== null) {
            $request->forceFill([
                'files_ingested' => ($request->files_ingested ?? 0) + $imported,
                'status' => OutboundRetrievalStatus::Ingested,
                'ingested_at' => now(),
            ])->save();
        }

        $this->audit->record(
            'outbound.package.ingested',
            'SUCCESS',
            $profile,
            [
                'profile_id' => $profile->id,
                'imported' => $imported,
                'skipped' => $skipped,
                'quarantined' => $quarantined,
                'errors' => $errors,
                // sem payload fiscal / XML
            ],
            $userId,
            $profile->office_id,
        );

        Log::info('outbound.package.ingest', [
            'profile_id' => $profile->id,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);

        return compact('imported', 'skipped', 'quarantined', 'errors', 'items');
    }

    /**
     * @return array{status: string, filename: string, access_key?: string, message?: string, sha256?: string}
     */
    private function ingestOneXml(
        OutboundCaptureProfile $profile,
        Establishment $establishment,
        string $xml,
        string $filename,
        ?MaOutboundRetrievalRequest $request,
    ): array {
        try {
            $meta = $this->validator->validateXml($xml, $establishment, $profile->environment);
        } catch (\Throwable $e) {
            return ['status' => 'error', 'filename' => $filename, 'message' => $e->getMessage()];
        }

        if ($meta['model']->value !== $profile->model->value) {
            return [
                'status' => 'error',
                'filename' => $filename,
                'message' => 'Modelo do XML diverge do perfil.',
            ];
        }

        $sha = hash('sha256', $xml);
        $officeId = $profile->office_id;
        $key = $meta['access_key'];

        return DB::transaction(function () use (
            $profile, $establishment, $xml, $filename, $request, $meta, $sha, $officeId, $key
        ) {
            $existing = DfeDocument::query()
                ->where('office_id', $officeId)
                ->where('access_key', $key)
                ->where('sha256', $sha)
                ->first();

            if ($existing !== null) {
                DocumentAcquisition::query()->firstOrCreate(
                    [
                        'dfe_document_id' => $existing->id,
                        'source' => DocumentAcquisitionSource::MaOfficialPackage->value,
                        'sha256' => $sha,
                    ],
                    [
                        'office_id' => $officeId,
                        'access_key' => $key,
                        'channel' => CaptureChannel::MaOutbound->value,
                        'is_canonical' => true,
                        'establishment_id' => $establishment->id,
                        'ma_outbound_retrieval_request_id' => $request?->id,
                    ]
                );

                return [
                    'status' => 'duplicate',
                    'filename' => $filename,
                    'access_key' => $key,
                    'sha256' => $sha,
                ];
            }

            // Mesma chave, bytes diferentes → quarentena
            $other = DfeDocument::query()
                ->where('office_id', $officeId)
                ->where('access_key', $key)
                ->where('sha256', '!=', $sha)
                ->first();

            // AAD canônico de DF-e: office_id + sha256 (mesma chave do export/download).
            $objectId = $this->store->put($xml, [
                'office_id' => $officeId,
                'sha256' => $sha,
            ]);

            $doc = DfeDocument::query()->create([
                'office_id' => $officeId,
                'sha256' => $sha,
                'document_type' => AdnDocumentType::Nfe,
                'schema_version' => 'procNFe_v4.00.xsd',
                'access_key' => $key,
                'vault_object_id' => $objectId,
                'byte_size' => strlen($xml),
                'parse_status' => $other ? 'QUARANTINE' : 'OK',
                'parse_alert' => $other ? 'Mesma chave com bytes divergentes' : null,
            ]);

            $isCanonical = $other === null;

            DocumentAcquisition::query()->create([
                'office_id' => $officeId,
                'dfe_document_id' => $doc->id,
                'access_key' => $key,
                'source' => DocumentAcquisitionSource::MaOfficialPackage,
                'channel' => CaptureChannel::MaOutbound,
                'sha256' => $sha,
                'is_canonical' => $isCanonical,
                'bytes_diverge_from_canonical' => ! $isCanonical,
                'quarantine_reason' => $isCanonical ? null : 'SHA divergente para a mesma chave',
                'establishment_id' => $establishment->id,
                'ma_outbound_retrieval_request_id' => $request?->id,
            ]);

            if ($isCanonical) {
                // Encerra recovery SVRS pendente da mesma chave (fallback assistido)
                try {
                    app(OutboundXmlRecoveryOrchestrator::class)
                        ->resolveByOtherSource($officeId, $key, 'MA_OFFICIAL_PACKAGE');
                    try {
                        app(OutboundDeadlineSatisfactionService::class)
                            ->markCapturedBySource($officeId, $key, 'MA_OFFICIAL_PACKAGE');
                    } catch (\Throwable) {
                    }
                } catch (\Throwable) {
                    // não bloquear ingestão
                }

                $parsed = $this->parser->parse($xml, 'procNFe');
                NfeDocument::query()->updateOrCreate(
                    [
                        'office_id' => $officeId,
                        'access_key' => $key,
                        'is_summary' => false,
                    ],
                    [
                        'dfe_document_id' => $doc->id,
                        'number' => (string) $meta['nnf'],
                        'series' => (string) $meta['series'],
                        'model' => $meta['model']->value,
                        'issuer_cnpj' => $meta['issuer_cnpj'],
                        'issuer_name' => $parsed['issuer_name'] ?? null,
                        'recipient_cnpj' => $parsed['recipient_cnpj'] ?? null,
                        'recipient_name' => $parsed['recipient_name'] ?? null,
                        'fiscal_role' => FiscalRole::Issuer,
                        'direction' => DocumentDirection::Out,
                        'purpose' => DocumentPurpose::Commercial,
                        'acquisition_source' => DocumentAcquisitionSource::MaOfficialPackage,
                        'issued_at' => $parsed['issued_at'] ?? null,
                        'total_amount' => $parsed['total_amount'] ?? null,
                        'status' => $meta['is_cancelled'] ? 'CANCELLED' : ($parsed['status'] ?? 'ACTIVE'),
                        'official_status_code' => $meta['cstat'],
                        'is_summary' => false,
                        'schema_hint' => 'procNFe',
                    ]
                );

                $this->markNumberCaptured($profile, $meta['series'], $meta['nnf'], $key, $doc->id);
            }

            return [
                'status' => $isCanonical ? 'imported' : 'quarantined',
                'filename' => $filename,
                'access_key' => $key,
                'sha256' => $sha,
                'message' => $isCanonical ? null : 'Bytes divergentes — quarentena',
            ];
        });
    }

    private function markNumberCaptured(
        OutboundCaptureProfile $profile,
        int $series,
        int $nnf,
        string $key,
        int $dfeId,
    ): void {
        if ($nnf < 1) {
            return;
        }

        $state = OutboundNumberState::query()
            ->where('outbound_capture_profile_id', $profile->id)
            ->where('series', $series)
            ->where('nnf', $nnf)
            ->first();

        if ($state === null) {
            return;
        }

        $state->forceFill([
            'status' => OutboundNumberStatus::Complete,
            'discovered_access_key' => $key,
            'xml_captured_at' => now(),
            'dfe_document_id' => $dfeId,
        ])->save();
    }

    private function looksLikeZip(string $name, string $bytes): bool
    {
        return str_ends_with(strtolower($name), '.zip')
            || str_starts_with($bytes, "PK\x03\x04");
    }

    /**
     * @return list<array{filename: string, bytes: string}>
     */
    private function extractZipXmls(string $zipBytes, string $zipName): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mazip');
        if ($tmp === false) {
            return [];
        }
        file_put_contents($tmp, $zipBytes);
        $zip = new ZipArchive;
        $out = [];
        try {
            if ($zip->open($tmp) !== true) {
                return [];
            }
            $count = min($zip->numFiles, self::MAX_FILES);
            for ($i = 0; $i < $count; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'] ?? "entry-{$i}";
                if (str_ends_with(strtolower($name), '/')) {
                    continue;
                }
                $content = $zip->getFromIndex($i);
                if ($content === false || $content === '') {
                    continue;
                }
                if (! str_contains($content, '<') && ! str_ends_with(strtolower($name), '.xml')) {
                    continue;
                }
                $out[] = ['filename' => $zipName.':'.$name, 'bytes' => $content];
            }
            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $out;
    }
}
