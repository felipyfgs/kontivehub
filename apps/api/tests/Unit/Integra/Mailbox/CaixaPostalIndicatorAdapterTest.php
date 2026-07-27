<?php

namespace Tests\Unit\Integra\Mailbox;

use App\Contracts\CaixaPostalIndicatorClient;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Mailbox\CaixaPostalIndicatorResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\MailboxContributorState;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\FiscalAdapterRegistry;
use App\Services\Integra\Mailbox\CaixaPostalIndicatorAdapter;
use App\Services\Integra\Mailbox\MailboxStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaixaPostalIndicatorAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_zero_is_persisted_as_unopened_diagnostic_without_reconciliation(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $run = FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_CAIXAPOSTAL',
            'service_code' => 'CAIXA_POSTAL',
            'operation_code' => 'INDICADOR',
            'operation_key' => 'caixa_postal.indicador',
            'trigger' => 'MANUAL',
            'idempotency_key' => 'indicator-test',
            'status' => 'RUNNING',
            'situation' => 'PROCESSING',
            'coverage' => 'UNKNOWN',
            'mutability' => 'READ_ONLY',
            'correlation_id' => 'indicator-test',
        ]);

        $clientFake = new class implements CaixaPostalIndicatorClient
        {
            public function getIndicator(array $context = []): CaixaPostalIndicatorResult
            {
                return new CaixaPostalIndicatorResult(true, 0);
            }
        };
        $adapter = new CaixaPostalIndicatorAdapter($clientFake, app(MailboxStateService::class));
        $result = $adapter->execute(new FiscalAdapterRequest(
            $tenant,
            $client,
            $run,
            'INTEGRA_CAIXAPOSTAL',
            'CAIXA_POSTAL',
            'INDICADOR',
            FiscalTrigger::Manual,
        ));

        $this->assertSame(FiscalCoverage::Partial, $result->coverage);
        $state = MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->firstOrFail();
        $this->assertSame(0, $state->new_messages_indicator);
        $this->assertNull($state->messages_observed_at);
        $this->assertFalse($state->toPublicArray()['new_messages_indicator']['reconciles_mailbox']);
    }

    public function test_registry_contains_real_mailbox_indicator_adapter(): void
    {
        $registry = app(FiscalAdapterRegistry::class);
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $run = FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_CAIXAPOSTAL',
            'service_code' => 'CAIXA_POSTAL',
            'operation_code' => 'INDICADOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'indicator-registry-test',
            'status' => 'QUEUED',
            'situation' => 'UNKNOWN',
            'coverage' => 'UNKNOWN',
            'mutability' => 'READ_ONLY',
        ]);
        $request = new FiscalAdapterRequest($tenant, $client, $run, 'INTEGRA_CAIXAPOSTAL', 'CAIXA_POSTAL', 'INDICADOR', FiscalTrigger::Manual);

        $this->assertInstanceOf(CaixaPostalIndicatorAdapter::class, $registry->resolve($request));
    }
}
