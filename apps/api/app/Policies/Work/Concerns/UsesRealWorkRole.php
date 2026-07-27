<?php

namespace App\Policies\Work\Concerns;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;

/**
 * Policies Work: leitura e mutação via TenantAuthorization.
 * Assignees/departamento ainda usam membership real (não RBAC).
 */
trait UsesRealWorkRole
{
    protected function currentTenant(): CurrentTenant
    {
        return app(CurrentTenant::class);
    }

    protected function auth(): TenantAuthorization
    {
        return app(TenantAuthorization::class);
    }

    protected function allowsWork(User $user, TenantPermission $permission, mixed $target = null): bool
    {
        return $this->auth()->allows($user, $permission, $target);
    }

    protected function sameTenantId(int $modelTenantId): bool
    {
        $tenantId = $this->currentTenant()->id();

        return $tenantId !== null && $tenantId === $modelTenantId;
    }

    protected function realMembershipId(): ?int
    {
        $id = $this->currentTenant()->realMembership()?->id;

        return $id !== null ? (int) $id : null;
    }

    protected function realWorkDepartmentId(): ?int
    {
        $id = $this->currentTenant()->realMembership()?->work_department_id;

        return $id !== null ? (int) $id : null;
    }
}
