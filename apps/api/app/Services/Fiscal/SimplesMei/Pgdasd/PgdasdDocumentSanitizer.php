<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdDocumentKind;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Carbon\CarbonImmutable;
use RuntimeException;

/** Valida PDFs oficiais, guarda bytes somente no cofre e remove Base64 da projeção pública. */
final class PgdasdDocumentSanitizer
{
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public function __construct(
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly PgdasdDocumentCodecs $codecs,
    ) {}

    /**
     * @return array{
     *   sanitized_dados:array<string,mixed>,
     *   artifacts:list<PgdasdArtifact>,
     *   evidence:list<FiscalEvidenceArtifact>,
     *   failures:list<array{path:string,reason:string}>
     * }
     */
    public function sanitizeAndStore(
        FiscalMonitoringRun $run,
        int $clientId,
        mixed $dados,
        string $operationKey,
        int $projectionId,
        ?string $periodKey = null,
        ?string $periodoApuracao = null,
        ?string $numeroDas = null,
        ?int $pgdasdOperationId = null,
    ): array {
        $documents = $this->codecs->extractDocumentFields($dados, $operationKey);
        $descriptors = [];
        $artifacts = [];
        $evidenceArtifacts = [];
        $failures = [];
        $observedAt = CarbonImmutable::now();

        foreach ($documents as $document) {
            $path = $document['path'];
            try {
                $bytes = $this->decodeStrictBase64($document['base64']);
                $this->assertPdf($bytes);

                $evidence = $this->evidenceStore->store(
                    run: $run,
                    bytes: $bytes,
                    contentType: 'application/pdf',
                    source: 'PGDASD_'.strtoupper(str_replace('.', '_', $operationKey)),
                    sourceVersion: '1',
                    observedAt: $observedAt,
                );

                $kind = $document['kind'] instanceof PgdasdDocumentKind
                    ? $document['kind']->value
                    : (string) $document['kind'];
                $dasNumber = $document['numero_das'] ?? $numeroDas;
                $operationId = $pgdasdOperationId ?? $this->resolveOperationId(
                    (int) $run->office_id,
                    $clientId,
                    $projectionId,
                    $document['numero_declaracao'],
                    $dasNumber,
                );

                $pgArtifact = PgdasdArtifact::query()
                    ->withoutGlobalScopes()
                    ->firstOrNew([
                        'office_id' => $run->office_id,
                        'client_id' => $clientId,
                        'kind' => $kind,
                        'fiscal_evidence_artifact_id' => $evidence->id,
                    ]);
                $pgArtifact->forceFill([
                    'projection_id' => $projectionId,
                    'operation_id' => $operationId,
                    'declaration_number' => $document['numero_declaracao'],
                    'das_number' => $dasNumber,
                    'filename' => $this->safeFilename(
                        $document['filename_hint'],
                        'pgdasd-'.strtolower($kind).'-'.$evidence->id.'.pdf',
                    ),
                    'content_type' => 'application/pdf',
                    'observed_at' => $observedAt,
                    'source_run_id' => $run->id,
                    'metadata' => [
                        'source_path' => $path,
                        'source_operation_key' => $operationKey,
                        'period_key' => $periodKey,
                        'periodo_apuracao' => $periodoApuracao,
                    ],
                ])->save();

                $artifacts[] = $pgArtifact->refresh();
                $evidenceArtifacts[] = $evidence;
                $descriptors[$path] = [
                    'sanitized' => true,
                    'available' => true,
                    'artifact_id' => $pgArtifact->id,
                    'kind' => $kind,
                    'content_type' => 'application/pdf',
                    'byte_size' => (int) $evidence->byte_size,
                    'download_path' => '/api/v1/fiscal/simples-mei/pgdasd/artifacts/'.$pgArtifact->id.'/download',
                ];
            } catch (\Throwable) {
                $failures[] = ['path' => $path, 'reason' => 'DOCUMENT_SANITIZE_FAILED'];
                $descriptors[$path] = [
                    'sanitized' => true,
                    'available' => false,
                    'reason' => 'DOCUMENT_SANITIZE_FAILED',
                ];
            }
        }

        return [
            'sanitized_dados' => $this->codecs->sanitizeDados($dados, $descriptors),
            'artifacts' => $artifacts,
            'evidence' => $evidenceArtifacts,
            'failures' => $failures,
        ];
    }

    public function decodeStrictBase64(string $base64): string
    {
        $clean = preg_replace('/\s+/', '', $base64) ?? '';
        $length = strlen($clean);
        $padding = str_ends_with($clean, '==') ? 2 : (str_ends_with($clean, '=') ? 1 : 0);
        $body = $padding > 0 ? substr($clean, 0, -$padding) : $clean;
        if ($clean === ''
            || $length % 4 !== 0
            || str_contains($body, '=')
            || strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') !== strlen($body)
        ) {
            throw new RuntimeException('Base64 inválido.');
        }
        $decoded = base64_decode($clean, true);
        if ($decoded === false || base64_encode($decoded) !== $clean) {
            throw new RuntimeException('Base64 não canônico.');
        }
        if (strlen($decoded) > self::MAX_PDF_BYTES) {
            throw new RuntimeException('PDF excede o limite permitido.');
        }

        return $decoded;
    }

    public function assertPdf(string $bytes): void
    {
        if (strlen($bytes) < 5 || ! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('Assinatura PDF inválida.');
        }
    }

    private function resolveOperationId(
        int $officeId,
        int $clientId,
        int $projectionId,
        ?string $declarationNumber,
        ?string $dasNumber,
    ): ?int {
        if ($declarationNumber === null && $dasNumber === null) {
            return null;
        }

        return PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('projection_id', $projectionId)
            ->when(
                $declarationNumber !== null,
                fn ($query) => $query->where('declaration_number', $declarationNumber),
                fn ($query) => $query->where('das_number', $dasNumber),
            )
            ->value('id');
    }

    private function safeFilename(?string $filename, string $fallback): string
    {
        $candidate = is_string($filename) ? $filename : '';
        $candidate = basename(str_replace(["\0", "\r", "\n", '\\'], ['', '', '', '/'], $candidate));
        $candidate = preg_replace('/[^\pL\pN._-]+/u', '_', $candidate) ?? '';
        $candidate = trim($candidate, '._-');
        if ($candidate === '') {
            $candidate = $fallback;
        }
        if (! str_ends_with(strtolower($candidate), '.pdf')) {
            $candidate .= '.pdf';
        }

        return mb_substr($candidate, 0, 180);
    }
}
