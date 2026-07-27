<?php

namespace Tests\Unit\Models;

use App\Enums\TenantPermission;
use App\Models\Tenant;
use App\Models\TenantPermissionProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class TenantPermissionProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_replaces_a_loaded_relation_and_increments_version(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::ClientsView]);
        $profile->load('permissionRows');
        $beforeVersion = (int) $profile->authorization_version;

        $profile->syncPermissionKeys([TenantPermission::FiscalDocumentsView]);

        $this->assertFalse($profile->relationLoaded('permissionRows'));
        $this->assertSame(
            [TenantPermission::FiscalDocumentsView->value],
            $profile->permissionKeys(),
        );
        $this->assertSame($beforeVersion + 1, (int) $profile->authorization_version);
    }

    public function test_invalid_input_preserves_permissions_and_version(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->create();
        $profile->syncPermissionKeys([TenantPermission::ClientsView]);
        $beforeVersion = (int) $profile->authorization_version;

        try {
            $profile->syncPermissionKeys(['permission.does_not_exist']);
            $this->fail('A permissão inválida deveria ser rejeitada.');
        } catch (InvalidArgumentException) {
            $this->assertSame([TenantPermission::ClientsView->value], $profile->permissionKeys());
            $this->assertSame($beforeVersion, (int) $profile->fresh()->authorization_version);
        }
    }

    public function test_unpersisted_profile_fails_before_writing_rows(): void
    {
        $tenant = Tenant::factory()->create();
        $profile = TenantPermissionProfile::factory()->forTenant($tenant)->make();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Perfil de permissão persistido é obrigatório.');

        $profile->syncPermissionKeys([TenantPermission::ClientsView]);
    }
}
