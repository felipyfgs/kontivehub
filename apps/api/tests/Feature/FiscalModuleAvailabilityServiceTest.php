<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalOperationClass;
use App\Models\FiscalModuleControl;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalModuleAvailabilityServiceTest extends TestCase
{
    use RefreshDatabase;

    private FiscalModuleAvailabilityService $availability;

    protected function setUp(): void
    {
        parent::setUp();
        $this->availability = app(FiscalModuleAvailabilityService::class);
        config(['fiscal.profile' => 'dev', 'fiscal.kill_switch' => false]);
    }

    public function test_absence_of_controls_means_available(): void
    {
        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, Office::factory()->create());

        $this->assertTrue($decision->allowed);
        $this->assertSame(FiscalModuleAvailabilityState::Available, $decision->state);
        $this->assertTrue($decision->toArray()['historical_data_visible']);
    }

    public function test_kill_switch_precedes_global_and_office_restrictions(): void
    {
        $office = Office::factory()->create();
        $this->restrict(FiscalModuleControlScope::Office, $office, 'Restrição local');
        config(['fiscal.kill_switch' => true]);

        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, $office);

        $this->assertSame('KILL_SWITCH', $decision->reasonCode);
    }

    public function test_global_restriction_precedes_office_restriction(): void
    {
        $office = Office::factory()->create();
        $this->restrict(FiscalModuleControlScope::Office, $office, 'Restrição local');
        $this->restrict(FiscalModuleControlScope::Global, null, 'Restrição global');

        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, $office);

        $this->assertSame('GLOBAL_RESTRICTION', $decision->reasonCode);
        $this->assertSame('Restrição global', $decision->reason);
    }

    public function test_office_restriction_does_not_affect_another_tenant(): void
    {
        $restricted = Office::factory()->create();
        $available = Office::factory()->create();
        $this->restrict(FiscalModuleControlScope::Office, $restricted, 'Pausa local');

        $this->assertFalse($this->availability->resolve(FiscalControlModule::Mailbox, $restricted)->allowed);
        $this->assertTrue($this->availability->resolve(FiscalControlModule::Mailbox, $available)->allowed);
    }

    public function test_production_blocks_document_generation_and_mutations(): void
    {
        config(['fiscal.profile' => 'production']);
        $office = Office::factory()->create();

        $document = $this->availability->resolve(FiscalControlModule::Guides, $office, FiscalOperationClass::DocumentGeneration);
        $mutation = $this->availability->resolve(FiscalControlModule::Guides, $office, FiscalOperationClass::FiscalMutation);

        $this->assertSame('PROFILE_OPERATION_BLOCKED', $document->reasonCode);
        $this->assertSame('FISCAL_MUTATION_BLOCKED', $mutation->reasonCode);
    }

    private function restrict(FiscalModuleControlScope $scope, ?Office $office, string $reason): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => $scope,
            'office_id' => $office?->id,
            'restricted' => true,
            'reason' => $reason,
            'updated_by_user_id' => User::factory()->create()->id,
        ]);
    }
}
