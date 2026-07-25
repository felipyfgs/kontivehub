<?php

namespace Tests\Feature;

use App\Contracts\SitfisPdfTextExtracting;
use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalMutability;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Models\Client;
use App\Models\FiscalFinding;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Services\FiscalMonitoring\SitfisSnapshotReprocessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SitfisPortfolioProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_counts_only_current_sitfis_snapshot_and_sitfis_pending_items(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $sitfisRun = $this->makeRun($office, $client, 'INTEGRA_SITFIS', 'SITFIS');
        $otherRun = $this->makeRun($office, $client, 'INTEGRA_DCTFWEB', 'DCTFWEB');
        $old = $this->snapshot($sitfisRun, false, 1);
        $current = $this->snapshot($sitfisRun, true, 2);

        $this->finding($old, 'HISTORICAL');
        $this->finding($current, 'CURRENT');
        $this->pending($sitfisRun, $current, 'SITFIS_PENDING_DEBITO');
        $this->pending($otherRun, $current, 'OTHER_MODULE_PENDING');

        $page = app(ModulePortfolioQueryService::class)->clients(
            $office,
            FiscalModuleKey::Sitfis,
            ModulePortfolioFilters::fromRequest([]),
        );

        $this->assertSame(1, $page->total());
        $detail = $page->items()[0]->detail;
        $this->assertSame($current->id, $detail['snapshot_id']);
        $this->assertSame(1, $detail['findings_count']);
        $this->assertSame(1, $detail['pending_count']);
    }

    public function test_local_reprocess_creates_idempotent_successor_without_remote_call(): void
    {
        $this->app->instance(SitfisPdfTextExtracting::class, new class implements SitfisPdfTextExtracting
        {
            public function extract(string $pdfBytes, int $maxTextBytes): string
            {
                return 'Não foram detectadas pendências/exigibilidades suspensas nos controles da Receita Federal e da Procuradoria-Geral da Fazenda Nacional.';
            }
        });

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $run = $this->makeRun($office, $client, 'INTEGRA_SITFIS', 'SITFIS');
        $evidence = app(FiscalEvidenceStore::class)->store(
            $run, '%PDF-1.4 local-only', 'application/pdf', 'SERPRO', '2.0',
        );
        $source = $this->snapshot($run, true, 1);
        $source->forceFill(['evidence_artifact_id' => $evidence->id]);
        // O modelo é imutável após persistência; associe o artefato diretamente para preparar a fixture.
        FiscalSnapshot::query()->withoutGlobalScopes()->whereKey($source->id)
            ->update(['evidence_artifact_id' => $evidence->id]);
        $this->finding($source, 'SITFIS_OLD');
        $this->pending($run, $source, 'SITFIS_OLD');
        $otherRun = $this->makeRun($office, $client, 'INTEGRA_DCTFWEB', 'DCTFWEB');
        $this->pending($otherRun, $source, 'OTHER_MODULE_PENDING');

        $service = app(SitfisSnapshotReprocessService::class);
        $first = $service->reprocess((int) $office->id, (int) $client->id, true);
        $second = $service->reprocess((int) $office->id, (int) $client->id, true);

        $this->assertSame(1, $first['changed']);
        $this->assertSame(0, $second['changed']);
        $this->assertFalse($source->fresh()->is_current);
        $successor = FiscalSnapshot::query()->withoutGlobalScopes()->where('is_current', true)->firstOrFail();
        $this->assertSame(FiscalSituation::UpToDate, $successor->situation);
        $this->assertSame($source->id, $successor->normalized['reprocessed_from_snapshot_id']);
        $this->assertSame(2, $successor->version);
        $this->assertFalse(FiscalFinding::query()->withoutGlobalScopes()->where('code', 'SITFIS_OLD')->firstOrFail()->is_active);
        $this->assertSame(FiscalPendingStatus::Resolved, FiscalPendingItem::query()->withoutGlobalScopes()->where('code', 'SITFIS_OLD')->firstOrFail()->status);
        $this->assertSame(FiscalPendingStatus::Open, FiscalPendingItem::query()->withoutGlobalScopes()->where('code', 'OTHER_MODULE_PENDING')->firstOrFail()->status);
    }

    private function makeRun(Office $office, Client $client, string $system, string $service): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id, 'client_id' => $client->id,
            'system_code' => $system, 'service_code' => $service, 'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual, 'idempotency_key' => $system.':'.$client->id,
            'status' => FiscalRunStatus::Completed, 'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full, 'mutability' => FiscalMutability::ReadOnly,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);
    }

    private function snapshot(FiscalMonitoringRun $run, bool $current, int $version): FiscalSnapshot
    {
        return FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $run->office_id, 'run_id' => $run->id, 'client_id' => $run->client_id,
            'system_code' => $run->system_code, 'service_code' => $run->service_code,
            'operation_code' => 'MONITOR', 'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full, 'version' => $version, 'is_current' => $current,
            'normalized' => [], 'observed_at' => now(), 'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);
    }

    private function finding(FiscalSnapshot $snapshot, string $code): void
    {
        FiscalFinding::query()->withoutGlobalScopes()->create([
            'office_id' => $snapshot->office_id, 'snapshot_id' => $snapshot->id,
            'run_id' => $snapshot->run_id, 'client_id' => $snapshot->client_id,
            'code' => $code, 'severity' => FiscalFindingSeverity::High,
            'title' => $code, 'situation' => FiscalSituation::Pending, 'is_active' => true,
        ]);
    }

    private function pending(FiscalMonitoringRun $run, FiscalSnapshot $snapshot, string $code): void
    {
        FiscalPendingItem::query()->withoutGlobalScopes()->create([
            'office_id' => $run->office_id, 'client_id' => $run->client_id,
            'snapshot_id' => $snapshot->id, 'run_id' => $run->id,
            'code' => $code, 'title' => $code, 'severity' => FiscalFindingSeverity::High,
            'status' => FiscalPendingStatus::Open, 'situation' => FiscalSituation::Pending,
            'logical_key' => $code, 'open_dedupe_key' => $code,
        ]);
    }
}
