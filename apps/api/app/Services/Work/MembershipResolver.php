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

    public function requireActiveMembership(int $membershipId): TenantMembership
    {
        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            abort(404);
        }

        $membership = TenantMembership::query()
            ->where('id', $membershipId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if ($membership === null) {
            throw ValidationException::withMessages([
                'assignee_membership_id' => ['Membership inválida ou inativa neste escritório.'],
            ]);
        }

        return $membership;
    }

    public function requireActiveDepartment(?int $departmentId): ?WorkDepartment
    {
        if ($departmentId === null) {
            return null;
        }

        $tenantId = $this->currentTenant->id();
        $dept = WorkDepartment::query()
            ->where('id', $departmentId)
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->first();

        if ($dept === null) {
            throw ValidationException::withMessages([
                'work_department_id' => ['Departamento inválido ou inativo neste escritório.'],
            ]);
        }

        return $dept;
    }
}
