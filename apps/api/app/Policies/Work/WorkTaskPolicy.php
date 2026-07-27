<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Models\WorkTask;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class WorkTaskPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, WorkTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $task);
    }

    public function update(User $user, WorkTask $task): bool
    {
        if (! $this->sameTenantId((int) $task->tenant_id)) {
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

    public function assign(User $user, WorkTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkAdminister, $task);
    }

    public function claim(User $user, WorkTask $task): bool
    {
        if (! $this->sameTenantId((int) $task->tenant_id)) {
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

    public function transition(User $user, WorkTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function dispense(User $user, WorkTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkAdminister, $task);
    }

    public function reopen(User $user, WorkTask $task): bool
    {
        return $this->dispense($user, $task);
    }

    public function comment(User $user, WorkTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkTasksExecute, $task);
    }

    public function uploadEvidence(User $user, WorkTask $task): bool
    {
        return $this->update($user, $task);
    }

    public function downloadEvidence(User $user, WorkTask $task): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkEvidenceDownload, $task);
    }

    public function bulk(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkTasksExecute)
            || $this->allowsWork($user, TenantPermission::WorkAdminister);
    }
}
