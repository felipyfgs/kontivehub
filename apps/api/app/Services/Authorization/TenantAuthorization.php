<?php

namespace App\Services\Authorization;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use App\Support\MultitenantRbac\EffectivePermissionsResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Resolvedor central de autorização tenant.
 *
 * Shadow mode (flag OFF): compara canônico × legado e obedece o legado.
 * Cutover (flag ON): obedece o canônico.
 * Cache de chaves por request (membership/profile/role) para evitar N+1.
 *
 * @see openspec/changes/padronizar-autorizacao-multitenant/design.md D5
 */
final class TenantAuthorization
{
    /** @var array<string, list<string>> */
    private array $legacyKeyCache = [];

    /** @var array<string, list<string>> */
    private array $canonicalKeyCache = [];

    /** @var array<string, bool> */
    private array $decisionCache = [];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly EffectivePermissionsResolver $effectivePermissions,
    ) {}

    public function allows(User $actor, TenantPermission $permission, mixed $target = null): bool
    {
        $cacheKey = $this->decisionCacheKey($actor, $permission, $target);
        if (array_key_exists($cacheKey, $this->decisionCache)) {
            return $this->decisionCache[$cacheKey];
        }

        $legacy = $this->legacyAllows($actor, $permission, $target);
        $canonical = $this->canonicalAllows($actor, $permission, $target);

        if ($legacy !== $canonical) {
            $this->recordDivergence($actor, $permission, $legacy, $canonical, $target);
        }

        $result = FeatureFlags::isCanonicalMultitenantRbacEnabled() ? $canonical : $legacy;
        $this->decisionCache[$cacheKey] = $result;

        return $result;
    }

    public function canonicalAllows(User $actor, TenantPermission $permission, mixed $target = null): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $office = $this->currentOffice->resolve($actor);
        if ($office === null) {
            return false;
        }

        if (! $office->lifecycle_status?->isOperational()) {
            return false;
        }

        if ($target !== null && ! $this->belongsToCurrentOffice($target, (int) $office->id)) {
            return false;
        }

        if ($this->currentOffice->isPlatformPrivileged()) {
            return $actor->isPlatformAdmin();
        }

        $membership = $this->currentOffice->realMembership();
        if ($membership === null || ! $membership->is_active) {
            return false;
        }

        $keys = $this->canonicalKeysForMembership($membership, (int) $office->id);

        return $this->keysAllow($keys, $permission);
    }

    public function legacyAllows(User $actor, TenantPermission $permission, mixed $target = null): bool
    {
        if (! $actor->is_active) {
            return false;
        }

        $office = $this->currentOffice->resolve($actor);
        if ($office === null) {
            return false;
        }

        if (! $office->lifecycle_status?->isOperational()) {
            return false;
        }

        if ($target !== null && ! $this->belongsToCurrentOffice($target, (int) $office->id)) {
            return false;
        }

        $role = $this->currentOffice->role();
        if ($role === null) {
            return false;
        }

        $keys = $this->legacyKeysForRole($role);

        return $this->keysAllow($keys, $permission);
    }

    /**
     * @return list<string>
     */
    private function legacyKeysForRole(OfficeRole $role): array
    {
        $key = 'legacy:'.$role->value;
        if (! isset($this->legacyKeyCache[$key])) {
            $this->legacyKeyCache[$key] = $this->effectivePermissions->fromLegacyOfficeRole($role);
        }

        return $this->legacyKeyCache[$key];
    }

    /**
     * @return list<string>
     */
    private function canonicalKeysForMembership(OfficeMembership $membership, int $officeId): array
    {
        $cacheKey = sprintf(
            'm:%d:v:%d:tr:%s:p:%s',
            (int) $membership->id,
            (int) $membership->authorization_version,
            $membership->tenant_role?->value ?? 'null',
            $membership->permission_profile_id ?? 'null',
        );

        if (isset($this->canonicalKeyCache[$cacheKey])) {
            return $this->canonicalKeyCache[$cacheKey];
        }

        $tenantRole = $membership->resolvedTenantRole();
        if ($tenantRole === TenantRole::TenantAdmin) {
            return $this->canonicalKeyCache[$cacheKey] = TenantPermission::orderedValues();
        }

        if ($tenantRole === TenantRole::TenantUser) {
            if ($membership->tenant_role instanceof TenantRole) {
                $profile = $membership->permissionProfile;
                if ($profile === null || ! $profile->is_active) {
                    return $this->canonicalKeyCache[$cacheKey] = [];
                }
                if (! $profile->belongsToOffice($officeId)) {
                    return $this->canonicalKeyCache[$cacheKey] = [];
                }

                return $this->canonicalKeyCache[$cacheKey] = $profile->permissionKeys();
            }

            // Pré-backfill: deriva do OfficeRole legado.
            if ($membership->role instanceof OfficeRole) {
                return $this->canonicalKeyCache[$cacheKey] = $this->legacyKeysForRole($membership->role);
            }

            return $this->canonicalKeyCache[$cacheKey] = [];
        }

        if ($membership->role instanceof OfficeRole) {
            return $this->canonicalKeyCache[$cacheKey] = $this->legacyKeysForRole($membership->role);
        }

        return $this->canonicalKeyCache[$cacheKey] = [];
    }

    private function belongsToCurrentOffice(mixed $target, int $officeId): bool
    {
        if (! $target instanceof Model) {
            return false;
        }

        if (! array_key_exists('office_id', $target->getAttributes())
            && $target->getAttribute('office_id') === null
            && ! array_key_exists('office_id', $target->getRelations())) {
            // Fail-closed se o model declara office_id no schema mas veio null/ausente.
            // Models sem tenancy fiscal não devem ser passados como target.
            return ! $this->modelDeclaresOfficeId($target);
        }

        $targetOfficeId = $target->getAttribute('office_id');

        return $targetOfficeId !== null && (int) $targetOfficeId === $officeId;
    }

    /**
     * `communication.manage` permanece aceito apenas para perfis persistidos
     * antes da chave canônica `communication.manage_inboxes`. Autorizações novas
     * devem solicitar a chave canônica; a equivalência evita um corte abrupto.
     *
     * @param  list<string>  $keys
     */
    private function keysAllow(array $keys, TenantPermission $permission): bool
    {
        if (in_array($permission->value, $keys, true)) {
            return true;
        }

        return $permission === TenantPermission::CommunicationManageInboxes
            && in_array(TenantPermission::CommunicationManage->value, $keys, true);
    }

    private function modelDeclaresOfficeId(Model $model): bool
    {
        return in_array('office_id', $model->getFillable(), true)
            || array_key_exists('office_id', $model->getCasts())
            || $model->isFillable('office_id');
    }

    private function decisionCacheKey(User $actor, TenantPermission $permission, mixed $target): string
    {
        $targetKey = 'none';
        if ($target instanceof Model) {
            $targetKey = $target::class.':'.($target->getKey() ?? 'new')
                .':'.($target->getAttribute('office_id') ?? 'null');
        }

        return implode('|', [
            (string) $actor->id,
            (string) $this->currentOffice->id(),
            $this->currentOffice->accessMode()?->value ?? 'none',
            $permission->value,
            $targetKey,
            FeatureFlags::isCanonicalMultitenantRbacEnabled() ? '1' : '0',
        ]);
    }

    private function recordDivergence(
        User $actor,
        TenantPermission $permission,
        bool $legacy,
        bool $canonical,
        mixed $target,
    ): void {
        Log::info('multitenant_rbac.shadow_divergence', [
            'user_id' => $actor->id,
            'office_id' => $this->currentOffice->id(),
            'permission' => $permission->value,
            'legacy' => $legacy,
            'canonical' => $canonical,
            'access_mode' => $this->currentOffice->accessMode()?->value,
            'target_type' => is_object($target) ? $target::class : gettype($target),
        ]);
    }
}
