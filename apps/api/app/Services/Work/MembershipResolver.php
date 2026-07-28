<?php

namespace App\Services\Work;

use App\Models\TenantMembership;
use App\Models\WorkDepartment;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;

/**
 * Resolve e valida memberships/departamentos no escritório da sessão.
 */
final class MembershipResolver
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    public function currentMembershipId(): ?int
    {
        return $this->currentTenant->realMembership()?->id;
    }

    public function requireActiveMembership(
        int $membershipId,
        bool $lockForUpdate = false,
    ): TenantMembership {
        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            abort(404);
        }

        $query = TenantMembership::query()
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $membership = $query->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'assignee_membership_id' => ['Membership inválida ou inativa neste escritório.'],
            ]);
        }

        return $membership;
    }

    public function requireActiveDepartment(
        ?int $departmentId,
        bool $lockForUpdate = false,
    ): ?WorkDepartment {
        if ($departmentId === null) {
            return null;
        }

        $tenantId = $this->currentTenant->id();
        $query = WorkDepartment::query()
            ->where('id', $departmentId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }
        $dept = $query->first();

        if ($dept === null) {
            throw ValidationException::withMessages([
                'work_department_id' => ['Departamento inválido ou inativo neste escritório.'],
            ]);
        }

        return $dept;
    }
}
