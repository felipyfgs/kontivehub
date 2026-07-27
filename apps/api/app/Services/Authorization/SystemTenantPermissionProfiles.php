<?php

namespace App\Services\Authorization;

use App\Enums\TenantPermission;
use App\Models\Tenant;
use App\Models\TenantPermissionProfile;
use Illuminate\Support\Facades\DB;

/**
 * Garante os perfis de sistema que tornam tenant_user uma membership válida.
 */
final class SystemTenantPermissionProfiles
{
    /**
     * @return array{operator: TenantPermissionProfile, viewer: TenantPermissionProfile}
     */
    public function ensure(Tenant $tenant): array
    {
        return DB::transaction(function () use ($tenant): array {
            $operator = $this->ensureProfile(
                $tenant,
                TenantPermissionProfile::SYSTEM_OPERATOR,
                'Operador',
                'Acesso operacional padrão do tenant.',
                TenantPermission::operatorSet(),
            );
            $viewer = $this->ensureProfile(
                $tenant,
                TenantPermissionProfile::SYSTEM_VIEWER,
                'Visualizador',
                'Acesso somente leitura do tenant.',
                TenantPermission::viewerSet(),
            );

            return compact('operator', 'viewer');
        });
    }

    /**
     * @param  list<TenantPermission>  $permissions
     */
    private function ensureProfile(
        Tenant $tenant,
        string $key,
        string $name,
        string $description,
        array $permissions,
    ): TenantPermissionProfile {
        $profile = TenantPermissionProfile::query()
            ->withoutGlobalScopes()
            ->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'key' => $key,
                ],
                [
                    'name' => $name,
                    'description' => $description,
                    'is_system' => true,
                    'is_active' => true,
                ],
            );

        $expected = array_map(
            static fn (TenantPermission $permission): string => $permission->value,
            $permissions,
        );
        sort($expected, SORT_STRING);

        if ($profile->permissionKeys() !== $expected) {
            $profile->syncPermissionKeys($permissions, allowSystem: true);
        }

        return $profile->refresh();
    }
}
