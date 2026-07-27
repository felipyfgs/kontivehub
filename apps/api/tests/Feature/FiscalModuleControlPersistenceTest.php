<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Models\FiscalModuleControl;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalModuleControlPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_and_tenant_controls_have_portable_unique_keys(): void
    {
        $actor = User::factory()->create();
        $tenant = Tenant::factory()->create();

        $global = FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Global,
            'tenant_id' => null,
            'restricted' => true,
            'reason' => 'Pausa global',
            'updated_by_user_id' => $actor->id,
        ]);
        $local = FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Tenant,
            'tenant_id' => $tenant->id,
            'restricted' => true,
            'reason' => 'Pausa local',
            'updated_by_user_id' => $actor->id,
        ]);

        $this->assertSame('GLOBAL:mailbox', $global->control_key);
        $this->assertSame("TENANT:{$tenant->id}:mailbox", $local->control_key);
        $this->assertTrue($tenant->fiscalModuleControls()->whereKey($local)->exists());
        $this->assertTrue($actor->updatedFiscalModuleControls()->whereKey($global)->exists());
    }

    public function test_duplicate_global_control_is_rejected_even_with_null_tenant(): void
    {
        $actor = User::factory()->create();
        $attributes = [
            'module_key' => FiscalControlModule::Dctfweb,
            'scope' => FiscalModuleControlScope::Global,
            'tenant_id' => null,
            'restricted' => true,
            'reason' => 'Manutenção',
            'updated_by_user_id' => $actor->id,
        ];
        FiscalModuleControl::query()->create($attributes);

        $this->expectException(QueryException::class);
        FiscalModuleControl::query()->create($attributes);
    }

    public function test_tenant_scope_requires_an_tenant(): void
    {
        $actor = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Guides,
            'scope' => FiscalModuleControlScope::Tenant,
            'tenant_id' => null,
            'restricted' => true,
            'reason' => 'Inválido',
            'updated_by_user_id' => $actor->id,
        ]);
    }
}
