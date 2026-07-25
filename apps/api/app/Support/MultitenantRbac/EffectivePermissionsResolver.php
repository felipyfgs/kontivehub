<?php

namespace App\Support\MultitenantRbac;

use App\Enums\OfficeRole;
use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Models\OfficeMembership;
use App\Models\User;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;

/**
 * Calcula effective_permissions para contrato HTTP alinhado à autoridade real.
 *
 * Flag OFF: espelha OfficeRole efetivo (sombra legada) — mesmas decisões das policies.
 * Flag ON: usa perfil canônico / baseline tenant_admin.
 */
final class EffectivePermissionsResolver
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    /**
     * @return list<string>
     */
    public function forCurrentContext(User $user): array
    {
        if (! $user->is_active) {
            return [];
        }

        $office = $this->currentOffice->resolve($user);
        if ($office === null) {
            return [];
        }

        if ($this->currentOffice->isPlatformPrivileged()) {
            return $user->isPlatformAdmin() ? TenantPermission::orderedValues() : [];
        }

        // Flag OFF: autoridade = papel efetivo de CurrentOffice (legado).
        if (! FeatureFlags::isCanonicalMultitenantRbacEnabled()) {
            return $this->fromLegacyOfficeRole($this->currentOffice->role());
        }

        $membership = $this->currentOffice->realMembership();
        if ($membership === null || ! $membership->is_active) {
            return [];
        }

        return $this->forMembership($membership);
    }

    /**
     * @return list<string>
     */
    public function forMembership(OfficeMembership $membership): array
    {
        if (! FeatureFlags::isCanonicalMultitenantRbacEnabled()) {
            return $this->fromLegacyOfficeRole(
                $membership->role instanceof OfficeRole ? $membership->role : null
            );
        }

        $tenantRole = $membership->resolvedTenantRole();

        if ($tenantRole === TenantRole::TenantAdmin) {
            return TenantPermission::orderedValues();
        }

        if ($tenantRole === TenantRole::TenantUser) {
            if ($membership->tenant_role instanceof TenantRole
                && $membership->permissionProfile?->is_active) {
                return $this->withCanonicalAliases($membership->permissionProfile->permissionKeys());
            }

            return $this->fromLegacyOfficeRole(
                $membership->role instanceof OfficeRole ? $membership->role : null
            );
        }

        if ($membership->role instanceof OfficeRole) {
            return $this->fromLegacyOfficeRole($membership->role);
        }

        return [];
    }

    /**
     * Mantém clientes antigos funcionais, mas sempre anuncia a chave nova para
     * que autorização de UI e API convirjam durante a migração dos perfis.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private function withCanonicalAliases(array $keys): array
    {
        if (in_array(TenantPermission::CommunicationManage->value, $keys, true)
            && ! in_array(TenantPermission::CommunicationManageInboxes->value, $keys, true)) {
            $keys[] = TenantPermission::CommunicationManageInboxes->value;
        }
        $keys = array_values(array_unique($keys));
        sort($keys, SORT_STRING);

        return $keys;
    }

    /**
     * @return list<string>
     */
    public function fromLegacyOfficeRole(?OfficeRole $role): array
    {
        if ($role === null) {
            return [];
        }

        if ($role === OfficeRole::Admin) {
            return TenantPermission::orderedValues();
        }

        if ($role === OfficeRole::Operator) {
            $keys = array_map(
                static fn (TenantPermission $p) => $p->value,
                TenantPermission::legacyOperatorSet()
            );
            sort($keys, SORT_STRING);

            return $keys;
        }

        if ($role === OfficeRole::Viewer) {
            $keys = array_map(
                static fn (TenantPermission $p) => $p->value,
                TenantPermission::legacyViewerSet()
            );
            sort($keys, SORT_STRING);

            return $keys;
        }

        return [];
    }
}
