<?php

namespace Database\Factories;

use App\Enums\TenantPermission;
use App\Models\Office;
use App\Models\TenantPermissionProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantPermissionProfile>
 */
class TenantPermissionProfileFactory extends Factory
{
    protected $model = TenantPermissionProfile::class;

    public function definition(): array
    {
        $suffix = fake()->unique()->bothify('custom-##??');

        return [
            'office_id' => Office::factory(),
            'key' => $suffix,
            'name' => 'Perfil '.$suffix,
            'description' => null,
            'is_system' => false,
            'is_active' => true,
            'authorization_version' => 1,
        ];
    }

    public function systemOperator(): static
    {
        return $this->state(fn () => [
            'key' => TenantPermissionProfile::SYSTEM_LEGACY_OPERATOR,
            'name' => 'Operador (sistema)',
            'is_system' => true,
            'is_active' => true,
        ])->afterCreating(function (TenantPermissionProfile $profile): void {
            $profile->syncPermissionKeys(TenantPermission::legacyOperatorSet(), allowSystem: true);
        });
    }

    public function systemViewer(): static
    {
        return $this->state(fn () => [
            'key' => TenantPermissionProfile::SYSTEM_LEGACY_VIEWER,
            'name' => 'Visualizador (sistema)',
            'is_system' => true,
            'is_active' => true,
        ])->afterCreating(function (TenantPermissionProfile $profile): void {
            $profile->syncPermissionKeys(TenantPermission::legacyViewerSet(), allowSystem: true);
        });
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }
}
