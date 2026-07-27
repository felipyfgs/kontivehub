<?php

namespace App\Services\Authorization;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\TenantMembership;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolvedor central e fail-closed de autorização no tenant corrente.
 */
final class TenantAuthorization
{
    /** @var array<string, list<string>> */
    private array $permissionCache = [];

    /** @var array<string, bool> */
    private array $decisionCache = [];

    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function allows(User $actor, TenantPermission $permission, mixed $target = null): bool
    {
        $cacheKey = $this->decisionCacheKey($actor, $permission, $target);

        return $this->decisionCache[$cacheKey] ??= $this->evaluate(
            $actor,
            $permission,
            $target,
        );
    }

    private function evaluate(User $actor, TenantPermission $permission, mixed $target): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null || ! $tenant->lifecycle_status?->isOperational()) {
            return false;
        }

        if ($target !== null && ! $this->belongsToCurrentTenant($target, (int) $tenant->id)) {
            return false;
        }

        if ($this->currentTenant->isPlatformPrivileged()) {
            return $actor->isPlatformAdmin();
        }

        $membership = $this->currentTenant->realMembership();
        if ($membership === null || ! $membership->is_active) {
            return false;
        }

        return in_array(
            $permission->value,
            $this->permissionKeys($membership, (int) $tenant->id),
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function permissionKeys(TenantMembership $membership, int $tenantId): array
    {
        $cacheKey = sprintf(
            'm:%d:v:%d:r:%s:p:%s',
            (int) $membership->id,
            (int) $membership->authorization_version,
            $membership->role?->value ?? 'null',
            $membership->permission_profile_id ?? 'null',
        );

        if (isset($this->permissionCache[$cacheKey])) {
            return $this->permissionCache[$cacheKey];
        }

        if ($membership->role === TenantRole::TenantAdmin) {
            return $this->permissionCache[$cacheKey] = TenantPermission::orderedValues();
        }

        if ($membership->role !== TenantRole::TenantUser) {
            return $this->permissionCache[$cacheKey] = [];
        }

        $profile = $membership->permissionProfile;
        if ($profile === null || ! $profile->is_active || ! $profile->belongsToTenant($tenantId)) {
            return $this->permissionCache[$cacheKey] = [];
        }

        return $this->permissionCache[$cacheKey] = $profile->permissionKeys();
    }

    private function belongsToCurrentTenant(mixed $target, int $tenantId): bool
    {
        if (! $target instanceof Model) {
            return false;
        }

        if (! array_key_exists('tenant_id', $target->getAttributes())
            && $target->getAttribute('tenant_id') === null
            && ! array_key_exists('tenant_id', $target->getRelations())) {
            return ! $this->modelDeclaresTenantId($target);
        }

        $targetTenantId = $target->getAttribute('tenant_id');

        return $targetTenantId !== null && (int) $targetTenantId === $tenantId;
    }

    private function modelDeclaresTenantId(Model $model): bool
    {
        return in_array('tenant_id', $model->getFillable(), true)
            || array_key_exists('tenant_id', $model->getCasts())
            || $model->isFillable('tenant_id');
    }

    private function decisionCacheKey(User $actor, TenantPermission $permission, mixed $target): string
    {
        $targetKey = 'none';
        if ($target instanceof Model) {
            $targetKey = $target::class.':'.($target->getKey() ?? 'new')
                .':'.($target->getAttribute('tenant_id') ?? 'null');
        }

        return implode('|', [
            (string) $actor->id,
            (string) $this->currentTenant->id(),
            $this->currentTenant->accessMode()?->value ?? 'none',
            $permission->value,
            $targetKey,
        ]);
    }
}
