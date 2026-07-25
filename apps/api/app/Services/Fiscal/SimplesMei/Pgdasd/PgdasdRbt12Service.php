<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdOperationKind;
use App\Enums\PgdasdRbt12Status;
use App\Jobs\Fiscal\FetchPgdasdRbt12Job;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\PgdasdRbt12Projection;
use App\Models\TaxObligationProjection;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Reserva e resolve RBT12 uma única vez por referência fiscal. */
final class PgdasdRbt12Service
{
    /** @var list<string> */
    public const RECOVERABLE_FAILURE_REASONS = [
        'EXTRACT_QUERY_FAILED',
        'EXTRACT_JOB_DISPATCH_FAILED',
        'EXTRACT_QUERY_ENQUEUE_FAILED',
        'EXTRACT_JOB_FAILED',
        'PDF_TEXT_EXTRACTION_FAILED',
    ];

    public function __construct(
        private readonly PgdasdRbt12Parser $parser,
        private readonly PdfTextExtractor $pdfText,
    ) {}

    /**
     * Reserva/reabre RBT12 do DAS mais recente do PA esperado (extrato) ou, sem DAS,
     * da declaração/recibo do mesmo PA; dispara a consulta documental correspondente.
     *
     * @param  list<PgdasdOperation>  $operations
     * @param  list<TaxObligationProjection>  $periodProjections
     * @return list<PgdasdRbt12Projection>
     */
    public function reserveFromOperations(
        FiscalMonitoringRun $run,
        array $operations,
        array $periodProjections = [],
        ?string $expectedPeriodKey = null,
    ): array {
        $expectedPeriodKey ??= $this->resolveExpectedPeriodKey($run);
        $projection = $this->resolveExpectedProjection(
            $run,
            $periodProjections,
            $expectedPeriodKey,
        );
        if ($projection === null) {
            return [];
        }

        $latestDeclaration = $this->latestDeclarationForProjection($projection);
        $dasOperations = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('client_id', $run->client_id)
            ->where('projection_id', $projection->id)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->orderByDesc('issued_at')
            ->orderByDesc('das_number')
            ->get();

        if ($dasOperations->isEmpty()) {
            // PA sem DAS: RBT12 ainda existe na declaração/recibo do mesmo PA.
            $reserved = $this->reserveFromDeclarationDocument($run, $projection, $latestDeclaration);
            if ($reserved !== null) {
                $this->pointProjectionAtLatestRbt12($projection, $reserved);

                return [$reserved];
            }

            $reserved = $this->reserveNoDas($run, $projection, $latestDeclaration);
            if ($reserved !== null) {
                $this->pointProjectionAtLatestRbt12($projection, $reserved);

                return [$reserved];
            }

            return [];
        }

        $das = $dasOperations->first(
            static fn (PgdasdOperation $item): bool => is_string($item->das_number) && $item->das_number !== ''
        );
        if ($das === null) {
            return [];
        }

        $key = $this->sourceReferenceKey(
            (int) $run->office_id,
            (int) $run->client_id,
            (string) $projection->period_key,
            (string) $das->das_number,
            $latestDeclaration?->declaration_number,
            $latestDeclaration?->transmitted_at?->toIso8601String(),
        );
        $reserved = $this->reserve(
            run: $run,
            projection: $projection,
            sourceKey: $key,
            status: PgdasdRbt12Status::Pending,
            dasNumber: (string) $das->das_number,
            latestDeclaration: $latestDeclaration,
        );
        if ($reserved === null) {
            return [];
        }

        $this->pointProjectionAtLatestRbt12($projection, $reserved);
        try {
            FetchPgdasdRbt12Job::dispatch((int) $reserved->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        } catch (\Throwable) {
            $this->markFailed($reserved, 'EXTRACT_JOB_DISPATCH_FAILED', (int) $run->id);
        }

        return [$reserved];
    }

    public function sourceReferenceKey(
        int $officeId,
        int $clientId,
        string $periodKey,
        string $dasNumber,
        ?string $latestDeclarationNumber,
        ?string $latestTransmission,
    ): string {
        return hash('sha256', implode('|', [
            $officeId,
            $clientId,
            $periodKey,
            $dasNumber,
            $latestDeclarationNumber ?? '',
            $latestTransmission ?? '',
        ]));
    }

    public function resolveFromPdfBytes(
        PgdasdRbt12Projection $projection,
        string $pdfBytes,
        int $artifactId,
        ?int $sourceRunId = null,
    ): PgdasdRbt12Projection {
        $metadata = is_array($projection->metadata) ? $projection->metadata : [];
        $periodKey = $projection->projection?->period_key
            ?? ($metadata['period_key'] ?? null);
        $periodoApuracao = is_string($periodKey) ? str_replace('-', '', $periodKey) : '';

        try {
            $parsed = $this->parser->parse($this->pdfText->extract($pdfBytes), $periodoApuracao);
        } catch (\Throwable) {
            return $this->markFailed($projection, 'PDF_TEXT_EXTRACTION_FAILED', $sourceRunId);
        }

        if (array_key_exists('rpa_cents', $parsed) && $parsed['rpa_cents'] !== null) {
            $metadata['rpa_cents'] = $parsed['rpa_cents'];
        }

        $projection->forceFill([
            'status' => $parsed['status'],
            'total_cents' => $parsed['total_cents'],
            'internal_market_cents' => $parsed['internal_market_cents'],
            'external_market_cents' => $parsed['external_market_cents'],
            'sanitized_error' => $parsed['reason'],
            'parser_version' => $parsed['parser_version'],
            'source_artifact_id' => $artifactId,
            'source_run_id' => $sourceRunId ?? $projection->source_run_id,
            'extracted_at' => CarbonImmutable::now(),
            'metadata' => $metadata,
        ])->save();

        return $projection->refresh();
    }

    public function markAttempted(PgdasdRbt12Projection $projection): bool
    {
        $updated = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->whereKey($projection->id)
            ->whereNull('attempted_at')
            ->where('status', PgdasdRbt12Status::Pending->value)
            ->update(['attempted_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($updated === 1) {
            return true;
        }

        // Reentregas de uma reserva ainda PENDING devem poder despachar a mesma
        // run idempotente; attempted_at não representa conclusão nem ACK remoto.
        return PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->whereKey($projection->id)
            ->where('status', PgdasdRbt12Status::Pending->value)
            ->exists();
    }

    public function markFailed(
        PgdasdRbt12Projection $projection,
        string $reason,
        ?int $sourceRunId = null,
    ): PgdasdRbt12Projection {
        $projection->forceFill([
            'status' => PgdasdRbt12Status::Failed,
            'sanitized_error' => mb_substr($reason, 0, 255),
            'parser_version' => PgdasdRbt12Parser::VERSION,
            'source_run_id' => $sourceRunId ?? $projection->source_run_id,
            'extracted_at' => CarbonImmutable::now(),
        ])->save();

        return $projection->refresh();
    }

    public function reconcileTerminalFailure(FiscalMonitoringRun $run): int
    {
        if (strtoupper((string) $run->operation_code) !== 'CONSULTAR_EXTRATO') {
            return 0;
        }

        return PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('client_id', $run->client_id)
            ->where('source_run_id', $run->id)
            ->where('status', PgdasdRbt12Status::Pending->value)
            ->update([
                'status' => PgdasdRbt12Status::Failed->value,
                'sanitized_error' => 'EXTRACT_QUERY_FAILED',
                'parser_version' => PgdasdRbt12Parser::VERSION,
                'extracted_at' => CarbonImmutable::now(),
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    public function isRecoverableFailure(?PgdasdRbt12Projection $projection): bool
    {
        if ($projection === null || $projection->status !== PgdasdRbt12Status::Failed) {
            return false;
        }

        $reason = (string) ($projection->sanitized_error ?? '');

        return in_array($reason, self::RECOVERABLE_FAILURE_REASONS, true);
    }

    /**
     * Reserva RBT12 a partir da declaração/recibo do PA esperado (sem DAS / sem movimento).
     */
    private function reserveFromDeclarationDocument(
        FiscalMonitoringRun $run,
        TaxObligationProjection $projection,
        ?PgdasdOperation $latestDeclaration,
    ): ?PgdasdRbt12Projection {
        $declarationNumber = is_string($latestDeclaration?->declaration_number)
            ? trim($latestDeclaration->declaration_number)
            : '';
        if ($declarationNumber === '') {
            return null;
        }

        $key = $this->sourceReferenceKey(
            (int) $run->office_id,
            (int) $run->client_id,
            (string) $projection->period_key,
            'DECLARATION',
            $declarationNumber,
            $latestDeclaration?->transmitted_at?->toIso8601String(),
        );
        $reserved = $this->reserve(
            run: $run,
            projection: $projection,
            sourceKey: $key,
            status: PgdasdRbt12Status::Pending,
            dasNumber: null,
            latestDeclaration: $latestDeclaration,
            metadataExtras: [
                'source_kind' => 'declaration_recibo',
                'period_key' => $projection->period_key,
            ],
        );
        if ($reserved === null) {
            return null;
        }

        // Garante número da declaração na reserva (reserve() já preenche via latestDeclaration).
        if ($reserved->source_declaration_number !== $declarationNumber) {
            $reserved->forceFill(['source_declaration_number' => $declarationNumber])->save();
            $reserved = $reserved->refresh();
        }

        try {
            FetchPgdasdRbt12Job::dispatch((int) $reserved->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        } catch (\Throwable) {
            $this->markFailed($reserved, 'EXTRACT_JOB_DISPATCH_FAILED', (int) $run->id);
        }

        return $reserved;
    }

    private function reserveNoDas(
        FiscalMonitoringRun $run,
        TaxObligationProjection $projection,
        ?PgdasdOperation $latestDeclaration,
    ): ?PgdasdRbt12Projection {
        $key = $this->sourceReferenceKey(
            (int) $run->office_id,
            (int) $run->client_id,
            (string) $projection->period_key,
            'NO_DAS',
            $latestDeclaration?->declaration_number,
            $latestDeclaration?->transmitted_at?->toIso8601String(),
        );

        return $this->reserve(
            run: $run,
            projection: $projection,
            sourceKey: $key,
            status: PgdasdRbt12Status::NoDas,
            dasNumber: null,
            latestDeclaration: $latestDeclaration,
        );
    }

    private function reserve(
        FiscalMonitoringRun $run,
        TaxObligationProjection $projection,
        string $sourceKey,
        PgdasdRbt12Status $status,
        ?string $dasNumber,
        ?PgdasdOperation $latestDeclaration,
        array $metadataExtras = [],
    ): ?PgdasdRbt12Projection {
        try {
            return DB::transaction(function () use (
                $run,
                $projection,
                $sourceKey,
                $status,
                $dasNumber,
                $latestDeclaration,
                $metadataExtras,
            ): ?PgdasdRbt12Projection {
                $existing = PgdasdRbt12Projection::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $run->office_id)
                    ->where('client_id', $run->client_id)
                    ->where('projection_id', $projection->id)
                    ->where('source_reference_key', $sourceKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    if ($status === PgdasdRbt12Status::Pending && $this->isRecoverableFailure($existing)) {
                        return $this->reopenRecoverableFailure($existing, $run, $projection);
                    }

                    return null;
                }

                $metadata = array_merge([
                    'period_key' => $projection->period_key,
                    'reservation_run_id' => $run->id,
                ], $metadataExtras);

                return PgdasdRbt12Projection::query()->create([
                    'office_id' => $run->office_id,
                    'client_id' => $run->client_id,
                    'projection_id' => $projection->id,
                    'source_reference_key' => $sourceKey,
                    'source_das_number' => $dasNumber,
                    'source_declaration_number' => $latestDeclaration?->declaration_number,
                    'source_transmitted_at' => $latestDeclaration?->transmitted_at,
                    'status' => $status,
                    'source_run_id' => $run->id,
                    'metadata' => $metadata,
                ]);
            });
        } catch (QueryException $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'unique')) {
                return null;
            }

            throw $exception;
        }
    }

    private function reopenRecoverableFailure(
        PgdasdRbt12Projection $existing,
        FiscalMonitoringRun $run,
        TaxObligationProjection $projection,
    ): PgdasdRbt12Projection {
        $metadata = is_array($existing->metadata) ? $existing->metadata : [];
        unset($metadata['extract_run_id']);
        $metadata['period_key'] = $projection->period_key;
        $metadata['reservation_run_id'] = $run->id;
        $metadata['reopened_from_failure'] = $existing->sanitized_error;

        $existing->forceFill([
            'status' => PgdasdRbt12Status::Pending,
            'sanitized_error' => null,
            'attempted_at' => null,
            'extracted_at' => null,
            'total_cents' => null,
            'internal_market_cents' => null,
            'external_market_cents' => null,
            'source_artifact_id' => null,
            'parser_version' => null,
            'source_run_id' => $run->id,
            'metadata' => $metadata,
        ])->save();

        return $existing->refresh();
    }

    /**
     * @param  list<TaxObligationProjection>  $periodProjections
     */
    private function resolveExpectedProjection(
        FiscalMonitoringRun $run,
        array $periodProjections,
        string $expectedPeriodKey,
    ): ?TaxObligationProjection {
        foreach ($periodProjections as $projection) {
            if ((string) $projection->period_key === $expectedPeriodKey
                && (int) $projection->office_id === (int) $run->office_id
                && (int) $projection->client_id === (int) $run->client_id
            ) {
                return $projection;
            }
        }

        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('client_id', $run->client_id)
            ->where('period_key', $expectedPeriodKey)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveExpectedPeriodKey(FiscalMonitoringRun $run): string
    {
        $progress = is_array($run->progress) ? $run->progress : [];
        $fromProgress = $progress['expected_period_key'] ?? $progress['period_key'] ?? null;
        if (is_string($fromProgress) && preg_match('/^\d{4}-\d{2}$/', $fromProgress) === 1) {
            return $fromProgress;
        }

        $office = Office::query()->find($run->office_id);
        $tz = is_string($office?->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';

        return PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, $tz));
    }

    private function latestDeclarationForProjection(TaxObligationProjection $projection): ?PgdasdOperation
    {
        return PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $projection->office_id)
            ->where('client_id', $projection->client_id)
            ->where('projection_id', $projection->id)
            ->where('kind', PgdasdOperationKind::Declaration->value)
            ->whereNotNull('transmitted_at')
            ->orderByDesc('transmitted_at')
            ->orderByDesc('declaration_number')
            ->first();
    }

    private function pointProjectionAtLatestRbt12(
        TaxObligationProjection $projection,
        PgdasdRbt12Projection $rbt12,
    ): void {
        $projection->forceFill(['pgdasd_latest_rbt12_projection_id' => $rbt12->id])->save();
    }
}
