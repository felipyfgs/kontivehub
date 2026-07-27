<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\SerproEnvironment;
use App\Enums\TenantSerproOnboardingStatus;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\Tenant;
use App\Models\TenantSerproOnboardingState;
use App\Services\Integra\TenantSerproOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TenantFiscalReadinessFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalization_marks_ready_and_queues_initial_collection_for_all_modules(): void
    {
        Queue::fake();
        $tenant = Tenant::factory()->create();
        $state = TenantSerproOnboardingState::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => TenantSerproOnboardingStatus::LoadingProxyPowers,
            'idempotency_key' => 'onboarding-v1',
            'last_step' => 'loading_proxy_powers',
        ]);

        $finished = app(TenantSerproOnboardingService::class)->finalizeReadiness(
            $tenant,
            SerproEnvironment::Trial,
            'onboarding-v1',
            batchId: 'batch-123',
        );

        $this->assertSame(TenantSerproOnboardingStatus::Ready, $finished->status);
        $this->assertSame('ready', $finished->last_step);
        $this->assertSame('batch-123', $finished->metadata['procuracao_batch_id']);
        Queue::assertPushed(RecoverFiscalModuleJob::class, count(FiscalControlModule::cases()));
        $this->assertSame($state->id, $finished->id);
    }
}
