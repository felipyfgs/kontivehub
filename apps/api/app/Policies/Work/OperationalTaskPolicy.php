<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\OperationalTask;
use App\Models\User;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class OperationalTaskPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, OperationalTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $task);
    }

    public function update(User $user, OperationalTask $task): bool
    {
        if (! $this->sameOfficeId((int) $task->office_id)) {
            return false;
        }

        if ($this->allowsWork($user, TenantPermission::WorkAdminister, $task)) {
            return true;
        }

        if (! $this->allowsWork($user, TenantPermission::WorkTasksExecute, $task)) {
            return false;
        }

        $membershipId = $this->realMembershipId();

        if ($task->assignee_membership_id !== null) {
            return (int) $task->assignee_membership_id === (int) $membershipId;
        }

        $dept = $this->realWorkDepartmentId();

        return $dept !== null && (int) $task->work_department_id === (int) $dept;
    }

    public function assign(User $user, OperationalTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkAdminister, $task);
    }

    public function claim(User $user, OperationalTask $task): bool
    {
        if (! $this->sameOfficeId((int) $task->office_id)) {
            return false;
        }
        if (! $this->allowsWork($user, TenantPermission::WorkTasksExecute, $task)) {
            return false;
        }
        if ($task->assignee_membership_id !== null) {
            return false;
        }
        $dept = $this->realWorkDepartmentId();

        return $dept !== null && (int) $task->work_department_id === (int) $dept;
    }

    public function transition(User $user, OperationalTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function dispense(User $user, OperationalTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkAdminister, $task);
    }

    public function reopen(User $user, OperationalTask $task): bool
    {
        return $this->dispense($user, $task);
    }

    public function comment(User $user, OperationalTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkTasksExecute, $task);
    }

    public function uploadEvidence(User $user, OperationalTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function downloadEvidence(User $user, OperationalTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkEvidenceDownload, $task);
    }

    public function bulk(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkTasksExecute)
            || $this->allowsWork($user, TenantPermission::WorkAdminister);
    }
}
