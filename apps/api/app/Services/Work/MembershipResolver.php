<?php

namespace App\Services\Work;

use App\Models\OfficeMembership;
use App\Models\WorkDepartment;
use App\Support\CurrentOffice;
use Illuminate\Validation\ValidationException;

/**
 * Resolve e valida memberships/departamentos no escritório da sessão.
 */
final class MembershipResolver
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
    ) {}

    public function currentMembershipId(): ?int
    {
        return $this->currentOffice->membership()?->id;
    }

    public function requireActiveMembership(int $membershipId): OfficeMembership
    {
        $officeId = $this->currentOffice->id();
        if ($officeId === null) {
            abort(404);
        }

        $membership = OfficeMembership::query()
            ->where('id', $membershipId)
            ->where('office_id', $officeId)
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

        $officeId = $this->currentOffice->id();
        $dept = WorkDepartment::query()
            ->where('id', $departmentId)
            ->where('office_id', $officeId)
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
