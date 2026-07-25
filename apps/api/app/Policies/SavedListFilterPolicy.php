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
 * - office: membership do Office lista; publicar = filters.share; excluir office de terceiros = admin baseline
 */
class SavedListFilterPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function view(User $user, SavedListFilter $filter): bool
    {
        if (! $this->sameOffice($user, $filter)
            || ! $this->allows($user, TenantPermission::ClientsView, $filter)) {
            return false;
        }

        if ($filter->isOfficeShared()) {
            return true;
        }

        return (int) $filter->user_id === (int) $user->id;
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function shareOffice(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiltersShare);
    }

    public function update(User $user, SavedListFilter $filter): bool
    {
        if (! $this->sameOffice($user, $filter)
            || ! $this->allows($user, TenantPermission::ClientsView, $filter)) {
            return false;
        }

        if ((int) $filter->user_id === (int) $user->id) {
            return true;
        }

        return $filter->isOfficeShared()
            && $this->allows($user, TenantPermission::TenantSettingsManage, $filter);
    }

    public function delete(User $user, SavedListFilter $filter): bool
    {
        return $this->update($user, $filter);
    }
}
