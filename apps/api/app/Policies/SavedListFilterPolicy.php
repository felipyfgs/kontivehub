<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\SavedListFilter;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

/**
 * Ownership e share de presets de filtro de lista.
 *
 * - personal: só o autor lista/edita/exclui
 * - tenant: membership do Tenant lista; publicar = filters.share; excluir tenant de terceiros = admin baseline
 */
class SavedListFilterPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user, string $surface): bool
    {
        return $this->allows($user, $this->permissionFor($surface));
    }

    public function view(User $user, SavedListFilter $filter): bool
    {
        if (! $this->sameTenant($user, $filter)
            || ! $this->allows($user, $this->permissionFor($filter->surface), $filter)) {
            return false;
        }

        if ($filter->isTenantShared()) {
            return true;
        }

        return (int) $filter->user_id === (int) $user->id;
    }

    public function create(User $user, string $surface): bool
    {
        return $this->allows($user, $this->permissionFor($surface));
    }

    public function shareTenant(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiltersShare);
    }

    public function update(User $user, SavedListFilter $filter): bool
    {
        if (! $this->sameTenant($user, $filter)
            || ! $this->allows($user, $this->permissionFor($filter->surface), $filter)) {
            return false;
        }

        if ((int) $filter->user_id === (int) $user->id) {
            return true;
        }

        return $filter->isTenantShared()
            && $this->allows($user, TenantPermission::TenantSettingsManage, $filter);
    }

    public function delete(User $user, SavedListFilter $filter): bool
    {
        return $this->update($user, $filter);
    }

    private function permissionFor(string $surface): TenantPermission
    {
        return $surface === SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS
            ? TenantPermission::CommunicationView
            : TenantPermission::ClientsView;
    }
}
