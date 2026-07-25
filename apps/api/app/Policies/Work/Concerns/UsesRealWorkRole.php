<?php

namespace App\Policies\Work\Concerns;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;

/**
 * Policies Work: leitura e mutação via TenantAuthorization.
 * Assignees/departamento ainda usam membership real (não RBAC).
 */
trait UsesRealWorkRole
{
    protected function currentOffice(): CurrentOffice
    {
        return app(CurrentOffice::class);
    }

    protected function auth(): TenantAuthorization
    {
        return app(TenantAuthorization::class);
    }

    protected function allowsWork(User $user, TenantPermission $permission, mixed $target = null): bool
    {
        return $this->auth()->allows($user, $permission, $target);
    }

    protected function sameOfficeId(int $modelOfficeId): bool
    {
        $officeId = $this->currentOffice()->id();

        return $officeId !== null && $officeId === $modelOfficeId;
    }

    protected function realMembershipId(): ?int
    {
        $id = $this->currentOffice()->realMembership()?->id;

        return $id !== null ? (int) $id : null;
    }

    protected function realWorkDepartmentId(): ?int
    {
        $id = $this->currentOffice()->realMembership()?->work_department_id;

        return $id !== null ? (int) $id : null;
    }
}
