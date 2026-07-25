<?php

namespace Tests\Feature;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Models\Client;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalAdapterRegistry;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalModuleRuntimeRestrictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_queued_run_revalidates_restriction_without_calling_adapter(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.kill_switch' => false,
        ]);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $adapter = new class implements FiscalSourceAdapter
        {
            public int $calls = 0;

            public function systemCode(): string
            {
                return 'TEST_GOVERNANCE';
            }

            public function serviceCode(): string
            {
                return 'MAILBOX_READ';
            }

            public function operationCode(): string
            {
                return 'MONITOR';
            }

            public function mutability(): FiscalMutability
            {
                return FiscalMutability::ReadOnly;
            }

            public function coverage(): FiscalCoverage
            {
                return FiscalCoverage::Full;
            }

            public function moduleKey(): ?string
            {
                return 'mailbox';
            }

            public function supports(FiscalAdapterRequest $request): bool
            {
                return true;
            }

            public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
            {
                $this->calls++;

                return FiscalAdapterResult::unsupported('Não deveria ser chamado neste teste.');
            }
        };
        app(FiscalAdapterRegistry::class)->register($adapter);

        $runs = app(FiscalMonitoringRunService::class);
        $run = $runs->enqueueManual(
            $office,
            $client,
            'TEST_GOVERNANCE',
            'MAILBOX_READ',
            dispatch: false,
        );

        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Office,
            'office_id' => $office->id,
            'restricted' => true,
            'reason' => 'Pausa após enfileiramento',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);

        $finished = $runs->execute((int) $run->id);

        $this->assertSame(0, $adapter->calls);
        $this->assertSame(FiscalRunResult::Blocked, $finished->result);
        $this->assertSame('OFFICE_RESTRICTION', $finished->error_code);
        $this->assertDatabaseHas('fiscal_module_controls', [
            'office_id' => $office->id,
            'blocked_jobs_count' => 1,
        ]);
    }
}
