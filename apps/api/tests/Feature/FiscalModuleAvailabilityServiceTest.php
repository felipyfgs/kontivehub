<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleAvailabilityState;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalOperationClass;
use App\Models\FiscalModuleControl;
use App\Models\Tenant;
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
        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, Tenant::factory()->create());

        $this->assertTrue($decision->allowed);
        $this->assertSame(FiscalModuleAvailabilityState::Available, $decision->state);
        $this->assertTrue($decision->toArray()['historical_data_visible']);
    }

    public function test_kill_switch_precedes_global_and_tenant_restrictions(): void
    {
        $tenant = Tenant::factory()->create();
        $this->restrict(FiscalModuleControlScope::Tenant, $tenant, 'Restrição local');
        config(['fiscal.kill_switch' => true]);

        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, $tenant);

        $this->assertSame('KILL_SWITCH', $decision->reasonCode);
    }

    public function test_global_restriction_precedes_tenant_restriction(): void
    {
        $tenant = Tenant::factory()->create();
        $this->restrict(FiscalModuleControlScope::Tenant, $tenant, 'Restrição local');
        $this->restrict(FiscalModuleControlScope::Global, null, 'Restrição global');

        $decision = $this->availability->resolve(FiscalControlModule::Mailbox, $tenant);

        $this->assertSame('GLOBAL_RESTRICTION', $decision->reasonCode);
        $this->assertSame('Restrição global', $decision->reason);
    }

    public function test_tenant_restriction_does_not_affect_another_tenant(): void
    {
        $restricted = Tenant::factory()->create();
        $available = Tenant::factory()->create();
        $this->restrict(FiscalModuleControlScope::Tenant, $restricted, 'Pausa local');

        $this->assertFalse($this->availability->resolve(FiscalControlModule::Mailbox, $restricted)->allowed);
        $this->assertTrue($this->availability->resolve(FiscalControlModule::Mailbox, $available)->allowed);
    }

    public function test_production_blocks_document_generation_and_mutations(): void
    {
        config(['fiscal.profile' => 'production']);
        $tenant = Tenant::factory()->create();

        $document = $this->availability->resolve(FiscalControlModule::Guides, $tenant, FiscalOperationClass::DocumentGeneration);
        $mutation = $this->availability->resolve(FiscalControlModule::Guides, $tenant, FiscalOperationClass::FiscalMutation);

        $this->assertSame('PROFILE_OPERATION_BLOCKED', $document->reasonCode);
        $this->assertSame('FISCAL_MUTATION_BLOCKED', $mutation->reasonCode);
    }

    private function restrict(FiscalModuleControlScope $scope, ?Tenant $tenant, string $reason): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => $scope,
            'tenant_id' => $tenant?->id,
            'restricted' => true,
            'reason' => $reason,
            'updated_by_user_id' => User::factory()->create()->id,
        ]);
    }
}
