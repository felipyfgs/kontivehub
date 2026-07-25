<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalVerificationState;
use App\Models\FiscalSnapshot;
use App\Services\Integra\Sitfis\SitfisReportParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reclassifica evidências SITFIS já armazenadas, sem qualquer chamada externa. */
final class SitfisSnapshotReprocessService
{
    public function __construct(
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly SitfisReportParser $parser,
        private readonly SitfisProjectionReconciler $reconciler,
    ) {}

    /**
     * @return array{examined:int,changed:int,skipped:int,rows:list<array<string,mixed>>}
     */
    public function reprocess(int $officeId, ?int $clientId = null, bool $apply = false): array
    {
        $system = (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS');
        $service = (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS');
        $snapshots = FiscalSnapshot::query()->withoutGlobalScopes()
            ->with(['evidence', 'run'])
            ->where('office_id', $officeId)
            ->where('system_code', $system)
            ->where('service_code', $service)
            ->where('is_current', true)
            ->whereNotNull('evidence_artifact_id')
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->orderBy('id')->get();

        $rows = [];
        $changed = 0;
        $skipped = 0;
        foreach ($snapshots as $snapshot) {
            if ($snapshot->evidence === null || $snapshot->run === null) {
                $skipped++;

                continue;
            }

            $bytes = $this->evidenceStore->readAuthorized($snapshot->evidence, $officeId);
            $parsed = $this->parser->parse($bytes);
            $different = $this->isDifferent($snapshot, $parsed->situation->value, $parsed->normalized);
            $rows[] = [
                'snapshot_id' => (int) $snapshot->id,
                'client_id' => (int) $snapshot->client_id,
                'from' => $snapshot->situation?->value,
                'to' => $parsed->situation->value,
                'sections' => $parsed->normalized['recognized_sections'] ?? [],
                'changed' => $different,
            ];

            if (! $different) {
                $skipped++;

                continue;
            }
            $changed++;
            if ($apply) {
                $this->promoteSuccessor($snapshot, $parsed->situation->value, $parsed->normalized, $parsed->findings);
            }
        }

        return ['examined' => $snapshots->count(), 'changed' => $changed, 'skipped' => $skipped, 'rows' => $rows];
    }

    /** @param array<string,mixed> $normalized */
    private function isDifferent(FiscalSnapshot $snapshot, string $situation, array $normalized): bool
    {
        $current = is_array($snapshot->normalized) ? $snapshot->normalized : [];

        return $snapshot->situation?->value !== $situation
            || ($current['parser_conclusion'] ?? null) !== ($normalized['parser_conclusion'] ?? null)
            || ($current['recognized_sections'] ?? null) !== ($normalized['recognized_sections'] ?? null);
    }

    /**
     * @param  array<string,mixed>  $normalized
     * @param  list<array<string,mixed>>  $findings
     */
    private function promoteSuccessor(FiscalSnapshot $source, string $situation, array $normalized, array $findings): void
    {
        DB::transaction(function () use ($source, $situation, $normalized, $findings): void {
            $locked = FiscalSnapshot::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($source->id);
            if (! $locked->is_current) {
                return;
            }
            $run = $locked->run;
            if ($run === null) {
                throw new RuntimeException('Snapshot SITFIS sem execução associada.');
            }

            $locked->forceFill(['is_current' => false])->save();
            $version = (int) FiscalSnapshot::query()->withoutGlobalScopes()
                ->where('office_id', $locked->office_id)
                ->where('client_id', $locked->client_id)
                ->where('system_code', $locked->system_code)
                ->where('service_code', $locked->service_code)
                ->when($locked->competence_id !== null,
                    fn ($query) => $query->where('competence_id', $locked->competence_id),
                    fn ($query) => $query->whereNull('competence_id'))
                ->max('version');

            $successor = FiscalSnapshot::query()->withoutGlobalScopes()->create([
                'office_id' => $locked->office_id,
                'run_id' => $locked->run_id,
                'client_id' => $locked->client_id,
                'competence_id' => $locked->competence_id,
                'evidence_artifact_id' => $locked->evidence_artifact_id,
                'system_code' => $locked->system_code,
                'service_code' => $locked->service_code,
                'operation_code' => $locked->operation_code,
                'operation_key' => $locked->operation_key,
                'source_provenance' => $locked->source_provenance,
                'verification_state' => FiscalVerificationState::Verified,
                'situation' => $situation,
                'coverage' => $locked->coverage,
                'version' => $version + 1,
                'is_current' => true,
                'normalized' => array_merge(is_array($locked->normalized) ? $locked->normalized : [], $normalized, [
                    'reprocessed_from_snapshot_id' => (int) $locked->id,
                    'reprocessed_at' => CarbonImmutable::now()->toIso8601String(),
                ]),
                'observed_at' => $locked->observed_at,
                'created_at' => CarbonImmutable::now(),
            ]);

            $run->forceFill(['situation' => $situation, 'verification_state' => FiscalVerificationState::Verified])->save();
            $this->reconciler->reconcile($successor, $findings);
        });
    }
}
