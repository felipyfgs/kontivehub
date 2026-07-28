<?php

namespace Tests\Feature\Fiscal;

use App\Contracts\FiscalMutationTransport;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalMutationStatus;
use App\Enums\SerproBillableClass;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMutationOperation;
use App\Models\SerproServiceCatalogEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Fiscal\Mutations\FiscalMutationPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class FiscalMutationCapabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_execute_requires_preflight_token_without_calling_transport(): void
    {
        [$user, $client] = $this->actor();
        $transport = $this->mock(FiscalMutationTransport::class);
        $transport->shouldNotReceive('execute');
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $this->postJson('/api/v1/fiscal/mutations', array_merge(
            $this->executePayload($client),
            ['idempotency_key' => (string) Str::uuid()],
        ))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('preflight_token');

        self::assertSame(0, FiscalMutationOperation::query()->withoutGlobalScopes()->count());
    }

    public function test_execute_rejects_wrong_capability_before_terminal_replay_or_transport(): void
    {
        [$user, $client, $tenant] = $this->actor();
        $transport = $this->mock(FiscalMutationTransport::class);
        $transport->shouldNotReceive('execute');
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $idempotencyKey = (string) Str::uuid();
        $operation = FiscalMutationOperation::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'requested_by' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'logical_key' => 'capability-test',
            'correlation_id' => (string) Str::uuid(),
            'preflight_token' => (string) Str::uuid(),
            'environment' => 'TRIAL',
            'solution_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'operation_key' => 'pgmei.gerardaspdf',
            'module_key' => 'simples_mei',
            'status' => FiscalMutationStatus::Confirmed,
            'confirmation_phrase' => 'CONFIRMAR GERAR_DAS',
            'confirmation_required' => true,
            'request_sanitized' => [],
            'request_payload_encrypted' => [],
            'request_payload_digest' => FiscalMutationPayload::digest([]),
            'eligibility_snapshot' => ['allowed' => true],
            'preflight_at' => now(),
            'preflight_expires_at' => now()->addMinutes(10),
        ]);

        $this->postJson('/api/v1/fiscal/mutations', array_merge(
            $this->executePayload($client),
            [
                'idempotency_key' => $idempotencyKey,
                'preflight_token' => (string) Str::uuid(),
                'operation_code' => 'TRANSMITIR',
            ],
        ))->assertNotFound()
            ->assertJsonMissingPath('data.preflight_token')
            ->assertJsonPath('mutation_operation_id', null)
            ->assertJsonPath('status', null);

        self::assertSame(FiscalMutationStatus::Confirmed, $operation->refresh()->status);
    }

    public function test_eligible_preflight_executes_and_replays_once_with_returned_key_and_capability(): void
    {
        [$user, $client] = $this->actor();
        $this->enableFixtureMutation();
        $transport = new CountingFiscalMutationTransport;
        $this->app->instance(FiscalMutationTransport::class, $transport);
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $preflight = $this->postJson(
            '/api/v1/fiscal/mutations/preflight',
            $this->mutationPayload($client),
        )->assertOk()
            ->assertJsonPath('data.eligible', true)
            ->assertJsonStructure(['data' => ['preflight_token', 'idempotency_key']]);

        $preflightToken = $preflight->json('data.preflight_token');
        $idempotencyKey = $preflight->json('data.idempotency_key');
        $confirmationPhrase = $preflight->json('data.confirmation_phrase');
        self::assertIsString($preflightToken);
        self::assertIsString($idempotencyKey);
        self::assertIsString($confirmationPhrase);

        $executePayload = array_merge($this->executePayload($client), [
            'idempotency_key' => $idempotencyKey,
            'preflight_token' => $preflightToken,
            'confirmation_phrase' => $confirmationPhrase,
        ]);

        $first = $this->postJson('/api/v1/fiscal/mutations', $executePayload)
            ->assertCreated()
            ->assertJsonPath('data.status', FiscalMutationStatus::Confirmed->value)
            ->assertJsonMissingPath('data.preflight_token');
        $operationId = $first->json('data.id');

        $this->postJson('/api/v1/fiscal/mutations', $executePayload)
            ->assertCreated()
            ->assertJsonPath('data.id', $operationId)
            ->assertJsonMissingPath('data.preflight_token');
        $this->getJson('/api/v1/fiscal/mutations/'.$operationId)
            ->assertOk()
            ->assertJsonMissingPath('data.preflight_token');

        self::assertSame(1, $transport->executeCalls);
    }

    public function test_denied_preflight_does_not_expose_capability(): void
    {
        [$user, $client] = $this->actor();
        Sanctum::actingAs($user);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($user);

        $this->postJson('/api/v1/fiscal/mutations/preflight', $this->mutationPayload($client))
            ->assertUnprocessable()
            ->assertJsonPath('data.eligible', false)
            ->assertJsonMissingPath('data.preflight_token')
            ->assertJsonStructure(['data' => ['idempotency_key']]);
    }

    /**
     * @return array{0: User, 1: Client, 2: Tenant}
     */
    private function actor(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->forTenant($tenant)->create();
        Establishment::factory()->forClient($client)->create();

        return [$user, $client, $tenant];
    }

    /**
     * @return array<string, mixed>
     */
    private function executePayload(Client $client): array
    {
        return array_merge($this->mutationPayload($client), [
            'confirmation_phrase' => 'CONFIRMAR GERAR_DAS',
            'confirmed' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function mutationPayload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'solution_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'operation_key' => 'pgmei.gerardaspdf',
            'module' => 'simples_mei',
            'payload' => [],
        ];
    }

    private function enableFixtureMutation(): void
    {
        config([
            'fiscal.kill_switch' => false,
            'features.global_enabled' => true,
            'features.mutating.enabled' => true,
            'features.mutating.kill_switch' => false,
            'features.modules.simples_mei.enabled' => true,
            'features.modules.simples_mei.mutating_enabled' => true,
            'features.modules.simples_mei.allow_all_tenants' => true,
            'features.modules.simples_mei.tenant_allowlist' => [],
            'fiscal_mutations.enabled' => true,
            'fiscal_mutations.kill_switch' => false,
            'fiscal_mutations.operations' => [
                'INTEGRA_MEI.PGMEI.GERAR_DAS' => [
                    'enabled' => true,
                    'allow_all_tenants' => true,
                    'tenant_allowlist' => [],
                ],
            ],
            'mei_automation.enabled' => true,
            'mei_automation.kill_switch' => false,
            'mei_automation.live_egress_enabled' => false,
            'mei_automation.fixture_enabled' => true,
            'mei_automation.allow_all_tenants' => true,
            'mei_automation.provider_policy.default' => 'portal',
            'mei_automation.provider_policy.operations' => [
                'pgmei.gerardaspdf' => 'portal',
            ],
        ]);

        SerproServiceCatalogEntry::query()->create([
            'catalog_version' => 1,
            'environment' => 'TRIAL',
            'operation_key' => 'pgmei.gerardaspdf',
            'solution_code' => 'INTEGRA_MEI',
            'service_code' => 'PGMEI',
            'operation_code' => 'GERAR_DAS',
            'label' => 'Gerar DAS MEI',
            'is_mutating' => true,
            'is_enabled' => true,
            'billable_class' => SerproBillableClass::NaoFaturavel,
            'coverage' => 'KNOWN',
        ]);
    }
}

final class CountingFiscalMutationTransport implements FiscalMutationTransport
{
    public int $executeCalls = 0;

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $this->executeCalls++;

        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['status' => 'CONFIRMED', 'protocol' => 'fixture-confirmed'],
            correlationId: $request->correlationId,
            operationKey: $request->operationKey,
        );
    }

    public function reconcile(IntegraRequest $request): IntegraResponse
    {
        return new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: ['status' => 'CONFIRMED'],
            correlationId: $request->correlationId,
            operationKey: $request->operationKey,
        );
    }
}
