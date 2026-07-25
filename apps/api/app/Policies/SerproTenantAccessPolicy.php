<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

/**
 * Dados fiscais/tenant SERPRO exigem CurrentOffice resolvido.
 * PLATFORM_ADMIN sem seleção privilegiada e sem membership não obtém acesso fiscal.
 */
final class SerproTenantAccessPolicy
{
    use AuthorizesTenantPermission;

    public function viewTenantSerpro(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiscalDocumentsView);
    }

    public function mutateTenantSerpro(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiscalMutationsExecute);
    }

    public function operateTenantSerpro(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiscalSyncTrigger);
    }
}
