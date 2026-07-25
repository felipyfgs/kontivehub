<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\OfficeRole;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\TaxProxyPower;
use App\Models\User;
use App\Services\Fiscal\ManualConsult\ManualConsultActionCatalog;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionService;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicy;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicyException;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManualConsultReadPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_direct_mutating_action_is_rejected_and_audited_before_enqueue(): void
    {
        Queue::fake();
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $actionId = $this->actionIdForOperation('dctfweb.gerarguia');

        try {
            app(ManualConsultExecutionService::class)->execute(
                office: $office,
                client: $client,
                actionId: $actionId,
                params: [],
                confirmed: true,
                actorUserId: $operator->id,
            );
            $this->fail('A action mutante deveria ter sido recusada.');
        } catch (ManualConsultReadPolicyException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame('MANUAL_OPERATION_NOT_READ', $e->reasonCode);
        }

        Queue::assertNothingPushed();
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        $audit = AuditLog::query()
            ->where('action', 'fiscal.monitoring.read_rejected')
            ->latest('id')
            ->firstOrFail();
        $this->assertSame('DENIED', $audit->result);
        $this->assertSame('MANUAL_OPERATION_NOT_READ', $audit->context['reason_code']);
        $this->assertSame('DOCUMENT_GENERATION', $audit->context['operation_class']);
        $this->assertSame('dispatcher', $audit->context['boundary']);
        $this->assertSanitizedAuditContext($audit->context);
    }

    public function test_allowed_read_action_is_tagged_for_worker_revalidation(): void
    {
        Queue::fake();
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $definition = app(ManualConsultActionCatalog::class)
            ->findByOperationKey('caixa_postal.lista');
        $this->assertNotNull($definition);
        $this->seedUsableProxyPower($office, $client, $definition->requiredProxyPowers);

        app(ManualConsultExecutionService::class)->execute(
            office: $office,
            client: $client,
            actionId: $definition->actionId,
            params: [],
            confirmed: true,
            actorUserId: $operator->id,
        );

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->sole();
        $this->assertSame($definition->operationKey, $run->operation_key);
        $this->assertTrue($run->progress['manual_consult']);
        $this->assertSame($definition->actionId, $run->progress['action_id']);
        Queue::assertPushed(
            ExecuteFiscalMonitoringRunJob::class,
            fn (ExecuteFiscalMonitoringRunJob $job): bool => $job->fiscalMonitoringRunId === $run->id,
        );
    }

    public function test_worker_blocks_workspace_run_when_actor_is_viewer(): void
    {
        Queue::fake();
        [$office, $viewer, $client] = $this->tenantContext(OfficeRole::Viewer);
        $definition = app(ManualConsultActionCatalog::class)
            ->findByOperationKey('pgdasd.consdeclaracao');
        $this->assertNotNull($definition);
        $run = $this->workspaceRun($office, $client, $viewer, $definition->actionId);

        (new ExecuteFiscalMonitoringRunJob($run->id))->handle(
            app(FiscalMonitoringRunService::class),
            app(ManualConsultReadPolicy::class),
        );

        $run->refresh();
        $this->assertSame(FiscalRunStatus::Blocked, $run->status);
        $this->assertSame('MANUAL_ROLE_DENIED', $run->error_code);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'fiscal.monitoring.read_rejected',
            'result' => 'DENIED',
            'subject_id' => $run->id,
        ]);
    }

    public function test_worker_rejects_mutating_action_even_when_run_was_injected_directly(): void
    {
        Queue::fake();
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $actionId = $this->actionIdForOperation('dctfweb.gerarguia');
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_DCTFWEB',
            'service_code' => 'DCTFWEB',
            'operation_code' => 'GERAR_GUIA',
            'operation_key' => 'dctfweb.gerarguia',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'manual-policy-mutating:'.fake()->uuid(),
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::Mutating,
            'triggered_by' => $operator->id,
            'progress' => [
                'manual_consult' => true,
                'action_id' => $actionId,
            ],
        ]);

        (new ExecuteFiscalMonitoringRunJob($run->id))->handle(
            app(FiscalMonitoringRunService::class),
            app(ManualConsultReadPolicy::class),
        );

        $run->refresh();
        $this->assertSame(FiscalRunStatus::Blocked, $run->status);
        $this->assertSame('MANUAL_OPERATION_NOT_READ', $run->error_code);
        $audit = AuditLog::query()
            ->where('subject_id', $run->id)
            ->where('action', 'fiscal.monitoring.read_rejected')
            ->firstOrFail();
        $this->assertSame('DOCUMENT_GENERATION', $audit->context['operation_class']);
        $this->assertSame('job', $audit->context['boundary']);
        $this->assertSanitizedAuditContext($audit->context);
    }

    public function test_worker_rechecks_production_readiness_before_adapter_resolution(): void
    {
        Queue::fake();
        config()->set('serpro.default_environment', 'PRODUCTION');
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $definition = app(ManualConsultActionCatalog::class)
            ->findByOperationKey('pgdasd.consdeclaracao');
        $this->assertNotNull($definition);
        $run = $this->workspaceRun($office, $client, $operator, $definition->actionId);

        (new ExecuteFiscalMonitoringRunJob($run->id))->handle(
            app(FiscalMonitoringRunService::class),
            app(ManualConsultReadPolicy::class),
        );

        $run->refresh();
        $this->assertSame(FiscalRunStatus::Blocked, $run->status);
        $this->assertSame('MANUAL_TOKEN_MISSING', $run->error_code);
        $audit = AuditLog::query()
            ->where('subject_id', $run->id)
            ->where('action', 'fiscal.monitoring.read_rejected')
            ->firstOrFail();
        $this->assertSame('job', $audit->context['boundary']);
        $this->assertSanitizedAuditContext($audit->context);
    }

    public function test_worker_blocks_when_production_token_exists_but_required_proxy_power_is_missing(): void
    {
        Queue::fake();
        config()->set('serpro.default_environment', 'PRODUCTION');
        [$office, $operator, $client] = $this->tenantContext(OfficeRole::Operator);
        $definition = app(ManualConsultActionCatalog::class)
            ->findByOperationKey('pgdasd.consdeclaracao');
        $this->assertNotNull($definition);
        $this->assertNotEmpty($definition->requiredProxyPowers);
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => 'CNPJ',
            'author_identity' => '11222333000181',
            'certificate_mode' => 'EXTERNAL_SIGNATURE',
            'procurador_token_vault_object_id' => '01J00000000000000000000000',
            'procurador_token_expires_at' => now()->addHour(),
        ]);
        $run = $this->workspaceRun($office, $client, $operator, $definition->actionId);

        (new ExecuteFiscalMonitoringRunJob($run->id))->handle(
            app(FiscalMonitoringRunService::class),
            app(ManualConsultReadPolicy::class),
        );

        $run->refresh();
        $this->assertSame(FiscalRunStatus::Blocked, $run->status);
        $this->assertSame('MANUAL_POWER_MISSING', $run->error_code);
        $audit = AuditLog::query()
            ->where('subject_id', $run->id)
            ->where('action', 'fiscal.monitoring.read_rejected')
            ->firstOrFail();
        $this->assertSame('MANUAL_POWER_MISSING', $audit->context['reason_code']);
        $this->assertSanitizedAuditContext($audit->context);
    }

    /** @return array{Office, User, Client} */
    private function tenantContext(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $actor = User::factory()->forOffice($office, $role)->create();
        $client = Client::factory()->for($office)->create();
        Sanctum::actingAs($actor);
        $currentOffice = app(CurrentOffice::class);
        $currentOffice->clear();
        $this->assertSame($office->id, $currentOffice->resolve($actor)?->id);

        return [$office, $actor, $client];
    }

    /**
     * @param  list<string>  $requiredPowers
     */
    private function seedUsableProxyPower(Office $office, Client $client, array $requiredPowers): void
    {
        $powerCode = $requiredPowers[0] ?? '00006';
        $contributorCnpj = '26461528000151';

        Establishment::factory()->forClient($client)->create([
            'office_id' => $office->id,
            'cnpj' => $contributorCnpj,
            'is_active' => true,
            'is_matrix' => true,
        ]);

        $auth = OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => 'CNPJ',
            'author_identity' => '11222333000181',
            'certificate_mode' => 'EXTERNAL_SIGNATURE',
            'procurador_token_vault_object_id' => '01J00000000000000000000000',
            'procurador_token_expires_at' => now()->addHour(),
        ]);

        TaxProxyPower::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'office_serpro_authorization_id' => $auth->id,
            'author_identity' => $auth->author_identity,
            'contributor_cnpj' => $contributorCnpj,
            'system_code' => 'CAIXAPOSTAL',
            'power_code' => $powerCode,
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'status' => TaxProxyPowerStatus::Active,
            'environment' => SerproEnvironment::Trial->value,
            'provenance' => 'API_VERIFIED',
            'accepted_at' => now(),
            'freshness_checked_at' => now(),
            'verified_at' => now(),
            'valid_to' => now()->addYear(),
        ]);
    }

    private function actionIdForOperation(string $operationKey): string
    {
        foreach (app(MonitoringSurfaceRegistry::class)->all() as $surface) {
            foreach ($surface->capabilities() as $capability) {
                foreach ($capability->actions as $action) {
                    if ($action->operationKey === $operationKey) {
                        return $surface->surfaceKey.':'.$action->actionKey;
                    }
                }
            }
        }

        $this->fail("Operação canônica não encontrada: {$operationKey}");
    }

    private function workspaceRun(
        Office $office,
        Client $client,
        User $actor,
        string $actionId,
    ): FiscalMonitoringRun {
        $definition = app(ManualConsultActionCatalog::class)->get($actionId);
        $codes = $definition->runCodes;
        $this->assertNotNull($codes);

        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => $codes['system'],
            'service_code' => $codes['service'],
            'operation_code' => $codes['operation'],
            'operation_key' => $definition->operationKey,
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'manual-policy:'.fake()->uuid(),
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
            'triggered_by' => $actor->id,
            'progress' => [
                'manual_consult' => true,
                'action_id' => $actionId,
            ],
        ]);
    }

    /** @param array<string, mixed> $context */
    private function assertSanitizedAuditContext(array $context): void
    {
        foreach (['operation_key', 'system_code', 'service_code', 'operation_code', 'payload'] as $key) {
            $this->assertArrayNotHasKey($key, $context);
        }
    }
}
