<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\FiscalRunStatus;
use App\Enums\PgdasdOperationAmountSource;
use App\Enums\PgdasdOperationKind;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Persiste amount_cents por operação DAS no ingest (extrato / GERAR_DAS). */
final class PgdasdOperationAmountService
{
    private const GAP_ENQUEUE_LIMIT = 8;

    public function __construct(
        private readonly PgdasdExtratoDasAmountParser $parser,
        private readonly PdfTextExtractor $pdfText,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    public function applyParsedAmount(
        Office $office,
        int $clientId,
        string $dasNumber,
        int $amountCents,
        PgdasdOperationAmountSource $source,
        ?string $parserVersion = null,
        ?int $artifactId = null,
    ): bool {
        $operation = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $clientId)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->where('das_number', $dasNumber)
            ->orderByDesc('id')
            ->first();

        if ($operation === null) {
            return false;
        }

        // Não sobrescrever GERAR_DAS com parse de extrato.
        if ($operation->amount_source === PgdasdOperationAmountSource::GerarDas->value
            && $source === PgdasdOperationAmountSource::ExtratoParse
            && $operation->amount_cents !== null
        ) {
            return false;
        }

        $operation->forceFill([
            'amount_cents' => $amountCents,
            'amount_source' => $source->value,
            'amount_parser_version' => $parserVersion,
            'amount_resolved_at' => CarbonImmutable::now(),
            'amount_source_artifact_id' => $artifactId,
        ])->save();

        return true;
    }

    public function applyFromGerarDasNormalized(
        Office $office,
        int $clientId,
        array $normalized,
    ): bool {
        $doc = $normalized['document_number'] ?? null;
        $amount = $normalized['amount'] ?? null;
        if (! is_string($doc) || trim($doc) === '' || ! is_numeric($amount)) {
            return false;
        }
        $cents = (int) round(((float) $amount) * 100);
        if ($cents < 0) {
            return false;
        }

        return $this->applyParsedAmount(
            office: $office,
            clientId: $clientId,
            dasNumber: trim($doc),
            amountCents: $cents,
            source: PgdasdOperationAmountSource::GerarDas,
        );
    }

    /** @param list<PgdasdArtifact> $artifacts */
    public function applyFromExtratoArtifacts(
        Office $office,
        int $clientId,
        ?string $numeroDas,
        array $artifacts,
    ): void {
        if ($numeroDas === null || trim($numeroDas) === '') {
            return;
        }
        $artifact = collect($artifacts)->first(
            static fn (PgdasdArtifact $candidate): bool => (string) $candidate->kind === 'EXTRATO'
        );
        if (! $artifact instanceof PgdasdArtifact) {
            return;
        }
        $this->applyFromExtratoArtifact($office, $clientId, trim($numeroDas), $artifact);
    }

    public function applyFromExtratoArtifact(
        Office $office,
        int $clientId,
        string $dasNumber,
        PgdasdArtifact $artifact,
    ): bool {
        $evidenceId = $artifact->fiscal_evidence_artifact_id;
        if ($evidenceId === null) {
            return false;
        }
        $evidence = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $evidenceId)
            ->where('office_id', $office->id)
            ->first();
        if ($evidence === null) {
            return false;
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized($evidence, (int) $office->id);
            $text = $this->pdfText->extract($bytes);
        } catch (Throwable $e) {
            Log::warning('pgdasd.extrato_das_amount.read_failed', [
                'office_id' => $office->id,
                'client_id' => $clientId,
                'das_number' => $dasNumber,
                'artifact_id' => $artifact->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $parsed = $this->parser->parse($text, $dasNumber);
        if ($parsed['ok'] !== true || $parsed['amount_cents'] === null) {
            return false;
        }

        return $this->applyParsedAmount(
            office: $office,
            clientId: $clientId,
            dasNumber: $dasNumber,
            amountCents: (int) $parsed['amount_cents'],
            source: PgdasdOperationAmountSource::ExtratoParse,
            parserVersion: $parsed['parser_version'],
            artifactId: (int) $artifact->id,
        );
    }

    /**
     * Pós-MONITOR: tenta extratos locais; enfileira CONSEXTRATO para gaps restantes.
     *
     * @return array{backfilled: int, enqueued: int}
     */
    public function coverAmountGapsAfterMonitor(
        Office $office,
        Client $client,
        FiscalMonitoringRun $originRun,
    ): array {
        $gaps = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->where('payment_located', false)
            ->whereNotNull('das_number')
            ->where('das_number', '!=', '')
            ->whereNull('amount_cents')
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'client_id', 'period_key', 'das_number']);

        $backfilled = 0;
        $toEnqueue = [];
        foreach ($gaps as $operation) {
            $dasNumber = (string) $operation->das_number;
            $artifact = PgdasdArtifact::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('kind', 'EXTRATO')
                ->where('das_number', $dasNumber)
                ->whereNotNull('fiscal_evidence_artifact_id')
                ->orderByDesc('id')
                ->first();

            if ($artifact instanceof PgdasdArtifact
                && $this->applyFromExtratoArtifact($office, (int) $client->id, $dasNumber, $artifact)
            ) {
                $backfilled++;

                continue;
            }

            $toEnqueue[] = $operation;
        }

        $enqueued = 0;
        foreach (array_slice($toEnqueue, 0, self::GAP_ENQUEUE_LIMIT) as $operation) {
            if ($this->enqueueExtratoForDas(
                $office,
                $client,
                (string) $operation->period_key,
                (string) $operation->das_number,
                (int) $originRun->id,
            )) {
                $enqueued++;
            }
        }

        return ['backfilled' => $backfilled, 'enqueued' => $enqueued];
    }

    private function enqueueExtratoForDas(
        Office $office,
        Client $client,
        string $periodKey,
        string $dasNumber,
        int $originRunId,
    ): bool {
        $correlationId = 'pgdasd-das-amt-'.substr(hash('sha256', implode('|', [
            $office->id,
            $client->id,
            $dasNumber,
        ])), 0, 40);

        // Resolve lazy: FiscalMonitoringRunService → PgdasdPostConsult → este serviço.
        try {
            $run = app(FiscalMonitoringRunService::class)->enqueueManual(
                office: $office,
                client: $client,
                systemCode: 'INTEGRA_SN',
                serviceCode: 'PGDASD',
                operationCode: 'CONSULTAR_EXTRATO',
                correlationId: $correlationId,
                dispatch: false,
            );
        } catch (Throwable) {
            return false;
        }

        $run->forceFill([
            'operation_key' => 'pgdasd.consextrato',
            'progress' => [
                'period_key' => $periodKey,
                'periodo_apuracao' => str_replace('-', '', $periodKey),
                'numero_das' => $dasNumber,
                'das_amount_gap' => true,
                'origin_run_id' => $originRunId,
            ],
        ])->save();

        // Espelha RBT12: enqueueManual(..., dispatch:false) cria QUEUED sem job —
        // despachar explicitamente. Não re-despachar COMPLETED/SKIPPED/RUNNING.
        $status = $run->status instanceof FiscalRunStatus
            ? $run->status
            : FiscalRunStatus::tryFrom((string) ($run->status ?? ''));

        if ($status === FiscalRunStatus::Completed || $status === FiscalRunStatus::Skipped) {
            return false;
        }

        if ($status === FiscalRunStatus::Failed || $status === FiscalRunStatus::Blocked) {
            $run->forceFill([
                'status' => FiscalRunStatus::Queued,
                'result' => null,
                'error_code' => null,
                'error_message' => null,
                'skip_reason' => null,
                'finished_at' => null,
                'started_at' => null,
                'lease_owner' => null,
                'locked_at' => null,
            ])->save();
        } elseif ($status !== null && $status !== FiscalRunStatus::Queued) {
            // RUNNING / REQUEUED / etc. — já em voo.
            return true;
        }

        try {
            ExecuteFiscalMonitoringRunJob::dispatch((int) $run->id)
                ->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
