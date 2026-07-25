<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

/**
 * Configuração unificada do escritório (perfil, consentimento, A1 canônico).
 * Mutações: tenant.settings.manage (admin baseline / privilegiado).
 * Leitura: tenant.settings.view.
 */
class OfficeSettingsPolicy
{
    use AuthorizesTenantPermission;

    public function view(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantSettingsView);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantSettingsManage);
    }
}
