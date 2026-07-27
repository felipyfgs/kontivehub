<?php

namespace App\Models;

use App\Enums\TenantPermission;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TenantPermissionProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use RuntimeException;

/**
 * Perfil de permissões isolado por tenant (tenant).
 * System profiles (`operator`, `viewer`) são imutáveis.
 */
#[Fillable([
    'tenant_id',
    'key',
    'name',
    'description',
    'is_system',
    'is_active',
    'authorization_version',
])]
class TenantPermissionProfile extends Model
{
    /** @use HasFactory<TenantPermissionProfileFactory> */
    use BelongsToTenant, HasFactory;

    public const SYSTEM_OPERATOR = 'operator';

    public const SYSTEM_VIEWER = 'viewer';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
            'authorization_version' => 'integer',
            'tenant_id' => 'integer',
        ];
    }

    protected static function newFactory(): TenantPermissionProfileFactory
    {
        return TenantPermissionProfileFactory::new();
    }

    public function permissionRows(): HasMany
    {
        return $this->hasMany(TenantPermissionProfilePermission::class, 'permission_profile_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(TenantMembership::class, 'permission_profile_id');
    }

    public function has(TenantPermission|string $permission): bool
    {
        $key = $permission instanceof TenantPermission ? $permission->value : $permission;

        return in_array($key, $this->permissionKeys(), true);
    }

    /**
     * @return list<string>
     */
    public function permissionKeys(): array
    {
        if ($this->relationLoaded('permissionRows')) {
            $keys = $this->permissionRows
                ->pluck('permission_key')
                ->map(static fn ($k) => (string) $k)
                ->all();
        } else {
            $keys = $this->permissionRows()->pluck('permission_key')->all();
            $keys = array_map('strval', $keys);
        }

        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    public function belongsToTenant(int $tenantId): bool
    {
        return (int) $this->tenant_id === $tenantId;
    }

    public function assertMutable(): void
    {
        if ($this->is_system) {
            throw new RuntimeException('Perfis de sistema são imutáveis.');
        }
    }

    /**
     * @param  list<TenantPermission|string>  $permissions
     */
    public function syncPermissionKeys(array $permissions, bool $allowSystem = false): void
    {
        if ($this->is_system && ! $allowSystem) {
            $this->assertMutable();
        }

        $keys = [];
        foreach ($permissions as $permission) {
            $key = $permission instanceof TenantPermission ? $permission->value : (string) $permission;
            $enum = TenantPermission::tryFrom($key);
            if ($enum === null || ! $enum->isActive()) {
                throw new InvalidArgumentException("Permissão desconhecida ou inativa: {$key}");
            }
            if (! $enum->isDelegable() && ! $allowSystem) {
                throw new InvalidArgumentException("Permissão não delegável: {$key}");
            }
            $keys[] = $enum->value;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        $profileId = filter_var($this->getKey(), FILTER_VALIDATE_INT);
        $tenantId = filter_var($this->tenant_id, FILTER_VALIDATE_INT);
        if (! $this->exists || $profileId === false || $profileId < 1 || $tenantId === false || $tenantId < 1) {
            throw new RuntimeException('Perfil de permissão persistido é obrigatório.');
        }

        $this->getConnection()->transaction(function () use ($allowSystem, $keys, $tenantId, $profileId): void {
            $locked = self::query()
                ->withoutGlobalScopes()
                ->whereKey($profileId)
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw new RuntimeException('Perfil de permissão persistido não está disponível.');
            }
            if ($locked->is_system && ! $allowSystem) {
                $locked->assertMutable();
            }

            $locked->permissionRows()->delete();
            if ($keys !== []) {
                $locked->permissionRows()->createMany(array_map(
                    static fn (string $key): array => ['permission_key' => $key],
                    $keys,
                ));
            }

            $locked->authorization_version = max(1, (int) $locked->authorization_version) + 1;
            $locked->save();

            $this->setRawAttributes($locked->getAttributes(), true);
        });

        $this->unsetRelation('permissionRows');
    }

    public function bumpAuthorizationVersion(): void
    {
        $this->authorization_version = max(1, (int) $this->authorization_version) + 1;
        $this->save();
    }

    public function activeMembershipCount(): int
    {
        return $this->memberships()->where('is_active', true)->count();
    }
}
