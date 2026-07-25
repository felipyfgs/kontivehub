<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproEnvironment;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\Office;
use App\Models\OfficeSerproOnboardingState;
use App\Services\Integra\OfficeSerproOnboardingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OfficeFiscalReadinessFinalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalization_marks_ready_and_queues_initial_collection_for_all_modules(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $state = OfficeSerproOnboardingState::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => OfficeSerproOnboardingStatus::LoadingProxyPowers,
            'idempotency_key' => 'onboarding-v1',
            'last_step' => 'loading_proxy_powers',
        ]);

        $finished = app(OfficeSerproOnboardingService::class)->finalizeReadiness(
            $office,
            SerproEnvironment::Trial,
            'onboarding-v1',
            batchId: 'batch-123',
        );

        $this->assertSame(OfficeSerproOnboardingStatus::Ready, $finished->status);
        $this->assertSame('ready', $finished->last_step);
        $this->assertSame('batch-123', $finished->metadata['procuracao_batch_id']);
        Queue::assertPushed(RecoverFiscalModuleJob::class, count(FiscalControlModule::cases()));
        $this->assertSame($state->id, $finished->id);
    }
}
