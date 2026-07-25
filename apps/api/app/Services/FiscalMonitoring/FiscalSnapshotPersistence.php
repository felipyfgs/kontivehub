<?php

namespace App\Services\FiscalMonitoring;

use App\DTO\Fiscal\FiscalPersistPayload;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalVerificationState;
use App\Models\FiscalFinding;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Persistência atômica: evidência + snapshot ANTES de findings/pendências.
 * Em falha entre evidência e projeção, snapshot permanece e projeções podem ser refeitas.
 */
final class FiscalSnapshotPersistence
{
    public function __construct(
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly FiscalSituationNormalizer $normalizer,
    ) {}

    /**
     * @return array{
     *     run: FiscalMonitoringRun,
     *     snapshot: ?FiscalSnapshot,
     *     evidence_id: ?int,
     *     findings_count: int,
     *     pending_count: int
     * }
     */
    public function persist(FiscalPersistPayload $payload): array
    {
        $run = $payload->run->fresh() ?? $payload->run;
        $hasEvidence = $payload->evidenceBytes !== null && $payload->evidenceBytes !== '';

        $guarded = $this->normalizer->normalize(
            $payload->situation,
            $payload->coverage,
            $hasEvidence,
            $payload->normalized,
        );

        // Fase 1 (transação): evidência + snapshot + status da run
        $phase1 = DB::transaction(function () use ($payload, $run, $hasEvidence, $guarded) {
            $locked = FiscalMonitoringRun::query()
                ->withoutGlobalScopes()
                ->whereKey($run->id)
                ->where('office_id', $run->office_id)
                ->lockForUpdate()
                ->firstOrFail();

            $hasParseAlert = collect($payload->findings)->contains(
                fn (array $finding): bool => in_array($finding['code'] ?? null, [
                    'SITFIS_LAYOUT_UNKNOWN',
                    'SITFIS_PDF_INCONCLUSIVE',
                ], true)
            );
            if ($hasParseAlert) {
                $locked->verification_state = FiscalVerificationState::ParseAlert;
                $locked->save();
            } elseif (
                $hasEvidence
                && in_array($payload->result, [FiscalRunResult::Success, FiscalRunResult::Partial], true)
                && ! $payload->shouldRequeue
            ) {
                // VERIFIED só após parse bem-sucedido com evidência (não no enqueue).
                $locked->verification_state = FiscalVerificationState::Verified;
                $locked->save();
            }

            $evidence = null;
            if ($hasEvidence) {
                $evidence = $this->evidenceStore->store(
                    run: $locked,
                    bytes: (string) $payload->evidenceBytes,
                    contentType: $payload->evidenceContentType,
                    source: $payload->evidenceSource,
                    sourceVersion: $payload->sourceVersion,
                );
            }

            $snapshot = null;
            // Requeue sem evidência (ex.: espera SITFIS): só avança progresso da run — não promove snapshot vazio a is_current.
            $requeueWithoutEvidence = $payload->shouldRequeue && ! $hasEvidence;
            // Snapshot só com evidência OU resultado terminal honesto (skipped/blocked/failed sem inventar em dia)
            if (! $requeueWithoutEvidence && ($evidence !== null || in_array($payload->result, [
                FiscalRunResult::Failed,
                FiscalRunResult::Blocked,
                FiscalRunResult::Skipped,
                FiscalRunResult::Success,
                FiscalRunResult::Partial,
                FiscalRunResult::Requeued,
            ], true))) {
                $snapshot = $this->createSnapshot($locked, $payload, $guarded, $evidence?->id);
            }

            $this->finalizeRun($locked, $payload, $guarded['situation'], $guarded['coverage']);

            return [
                'run' => $locked->fresh(),
                'snapshot' => $snapshot,
                'evidence_id' => $evidence?->id,
            ];
        });

        // Fase 2: projeções (findings/pendências) — se falhar, evidência/snapshot já estão seguros
        $findingsCount = 0;
        $pendingCount = 0;
        $snapshot = $phase1['snapshot'];

        if ($snapshot !== null && $hasEvidence) {
            try {
                $proj = DB::transaction(function () use ($phase1, $payload, $snapshot, $guarded) {
                    return $this->projectFindingsAndPendings(
                        $phase1['run'],
                        $snapshot,
                        $payload->findings,
                        $guarded['situation'],
                    );
                });
                $findingsCount = $proj['findings_count'];
                $pendingCount = $proj['pending_count'];
            } catch (Throwable $e) {
                report($e);
                // Projeções podem ser reprocessadas; não reverte evidência.
            }
        }

        return [
            'run' => $phase1['run']->fresh(),
            'snapshot' => $snapshot?->fresh(),
            'evidence_id' => $phase1['evidence_id'],
            'findings_count' => $findingsCount,
            'pending_count' => $pendingCount,
        ];
    }

    /**
     * Reprocessa projeções a partir de um snapshot existente (pós-falha).
     *
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool,due_at?:string|null}>  $findings
     * @return array{findings_count:int,pending_count:int}
     */
    public function reproject(FiscalSnapshot $snapshot, array $findings): array
    {
        $run = $snapshot->run;
        if ($run === null) {
            throw new RuntimeException('Snapshot sem run associada.');
        }

        return DB::transaction(function () use ($run, $snapshot, $findings) {
            return $this->projectFindingsAndPendings(
                $run,
                $snapshot,
                $findings,
                $snapshot->situation ?? FiscalSituation::Unknown,
            );
        });
    }

    /**
     * @param  array{situation: FiscalSituation, coverage: FiscalCoverage, normalized: array<string, mixed>}  $guarded
     */
    private function createSnapshot(
        FiscalMonitoringRun $run,
        FiscalPersistPayload $payload,
        array $guarded,
        ?int $evidenceId,
    ): FiscalSnapshot {
        $isCurrentEligible = $run->source_provenance?->value !== 'UNVERIFIED'
            && ! (
                $run->verification_state?->value === 'PARSE_ALERT'
                && $evidenceId === null
            )
            && ! ($evidenceId === null && in_array($payload->result, [
                FiscalRunResult::Failed,
                FiscalRunResult::Blocked,
                FiscalRunResult::Skipped,
            ], true));

        // Só demove o corrente se o novo for elegível — PARSE_ALERT sem evidência
        // e falhas sem evidência não podem deixar o cliente sem snapshot is_current válido.
        // PARSE_ALERT/sucesso com evidência (ex. PDF) MAY promover e demover ERROR stale.
        if ($isCurrentEligible) {
            FiscalSnapshot::query()
                ->withoutGlobalScopes()
                ->where('office_id', $run->office_id)
                ->where('client_id', $run->client_id)
                ->where('system_code', $run->system_code)
                ->where('service_code', $run->service_code)
                ->when(
                    $run->competence_id !== null,
                    fn ($q) => $q->where('competence_id', $run->competence_id),
                    fn ($q) => $q->whereNull('competence_id'),
                )
                ->where('is_current', true)
                ->update(['is_current' => false]);
        }

        $version = (int) FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('client_id', $run->client_id)
            ->where('system_code', $run->system_code)
            ->where('service_code', $run->service_code)
            ->when(
                $run->competence_id !== null,
                fn ($q) => $q->where('competence_id', $run->competence_id),
                fn ($q) => $q->whereNull('competence_id'),
            )
            ->max('version');

        return FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $run->office_id,
            'run_id' => $run->id,
            'client_id' => $run->client_id,
            'competence_id' => $run->competence_id,
            'evidence_artifact_id' => $evidenceId,
            'system_code' => $run->system_code,
            'service_code' => $run->service_code,
            'operation_code' => $run->operation_code,
            'operation_key' => $run->operation_key,
            'source_provenance' => $run->source_provenance,
            'verification_state' => $run->verification_state,
            'situation' => $guarded['situation'],
            'coverage' => $guarded['coverage'],
            'version' => $version + 1,
            'is_current' => $isCurrentEligible,
            'normalized' => $guarded['normalized'],
            'observed_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @param  array{situation: FiscalSituation, coverage: FiscalCoverage, normalized: array<string, mixed>}  $guarded
     */
    private function finalizeRun(
        FiscalMonitoringRun $run,
        FiscalPersistPayload $payload,
        FiscalSituation $situation,
        $coverage,
    ): void {
        $status = match ($payload->result) {
            FiscalRunResult::Success, FiscalRunResult::Partial => FiscalRunStatus::Completed,
            FiscalRunResult::Failed => FiscalRunStatus::Failed,
            FiscalRunResult::Skipped => FiscalRunStatus::Skipped,
            FiscalRunResult::Blocked => FiscalRunStatus::Blocked,
            FiscalRunResult::Requeued => FiscalRunStatus::Requeued,
        };

        if ($payload->shouldRequeue) {
            $status = FiscalRunStatus::Requeued;
            $result = FiscalRunResult::Requeued;
        } else {
            $result = $payload->result;
        }

        $run->forceFill([
            'status' => $status,
            'result' => $result,
            'situation' => $situation,
            'coverage' => $coverage,
            'progress_cursor' => $payload->progressCursor ?? $run->progress_cursor,
            'progress' => $payload->progress !== [] ? $payload->progress : $run->progress,
            'items_processed' => $run->items_processed + $payload->itemsProcessed,
            'pages_processed' => $run->pages_processed + $payload->pagesProcessed,
            'skip_reason' => $payload->skipReason !== null
                ? mb_substr($payload->skipReason, 0, 80)
                : null,
            'error_code' => $payload->errorCode,
            'error_message' => $payload->errorMessage !== null
                ? mb_substr($payload->errorMessage, 0, 500)
                : null,
            'finished_at' => $payload->shouldRequeue ? null : CarbonImmutable::now(),
            'requeued_at' => $payload->shouldRequeue ? CarbonImmutable::now() : $run->requeued_at,
            'locked_at' => null,
            'lease_owner' => null,
        ])->save();
    }

    /**
     * @param  list<array{code:string,severity?:string,title:string,detail?:string,situation?:string,creates_pending?:bool,due_at?:string|null}>  $findings
     * @return array{findings_count:int,pending_count:int}
     */
    private function projectFindingsAndPendings(
        FiscalMonitoringRun $run,
        FiscalSnapshot $snapshot,
        array $findings,
        FiscalSituation $situation,
    ): array {
        // UNSUPPORTED/UNKNOWN sem evidência de pendência real: não cria pendência fictícia
        $allowPending = ! in_array($situation, [
            FiscalSituation::Unsupported,
            FiscalSituation::Unknown,
            FiscalSituation::NotApplicable,
            FiscalSituation::Blocked,
        ], true);

        $findingsCount = 0;
        $pendingCount = 0;

        foreach ($findings as $row) {
            $code = (string) ($row['code'] ?? '');
            if ($code === '') {
                continue;
            }

            $severity = FiscalFindingSeverity::tryFrom(strtoupper((string) ($row['severity'] ?? 'INFO')))
                ?? FiscalFindingSeverity::Info;
            $findingSituation = isset($row['situation'])
                ? (FiscalSituation::tryFrom(strtoupper((string) $row['situation'])) ?? $situation)
                : $situation;

            $finding = FiscalFinding::query()->updateOrCreate(
                [
                    'office_id' => $run->office_id,
                    'snapshot_id' => $snapshot->id,
                    'code' => $code,
                ],
                [
                    'run_id' => $run->id,
                    'client_id' => $run->client_id,
                    'severity' => $severity,
                    'title' => (string) ($row['title'] ?? $code),
                    'detail' => $row['detail'] ?? null,
                    'situation' => $findingSituation,
                    'is_active' => true,
                    'resolved_at' => null,
                    'metadata' => null,
                ]
            );
            $findingsCount++;

            $createsPending = (bool) ($row['creates_pending'] ?? false);
            if (! $createsPending || ! $allowPending) {
                continue;
            }

            $logical = FiscalIdempotency::pendingLogicalKey(
                $run->system_code,
                $run->service_code,
                $code,
                $run->competence?->period_key,
            );

            FiscalPendingItem::query()->updateOrCreate(
                [
                    'office_id' => $run->office_id,
                    'client_id' => $run->client_id,
                    'open_dedupe_key' => $logical,
                ],
                [
                    'snapshot_id' => $snapshot->id,
                    'run_id' => $run->id,
                    'fiscal_category_id' => $run->fiscal_category_id,
                    'competence_id' => $run->competence_id,
                    'finding_id' => $finding->id,
                    'code' => $code,
                    'title' => (string) ($row['title'] ?? $code),
                    'detail' => $row['detail'] ?? null,
                    'severity' => $severity,
                    'status' => FiscalPendingStatus::Open,
                    'situation' => FiscalSituation::Pending,
                    'due_at' => isset($row['due_at']) ? CarbonImmutable::parse((string) $row['due_at']) : null,
                    'resolved_at' => null,
                    'logical_key' => $logical,
                    'metadata' => null,
                ]
            );
            $pendingCount++;
        }

        return [
            'findings_count' => $findingsCount,
            'pending_count' => $pendingCount,
        ];
    }
}
