<?php

namespace App\Actions\Work;

use App\DTO\Work\WorkTaskAssignmentData;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Services\Work\MembershipResolver;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\DB;

final readonly class AssignWorkTaskAction
{
    public function __construct(
        private MembershipResolver $memberships,
        private AuditLogger $audit,
    ) {}

    public function execute(
        WorkTask $task,
        WorkTaskAssignmentData $data,
    ): WorkTask {
        return DB::transaction(function () use ($task, $data): WorkTask {
            if (isset($data->attributes['assignee_membership_id'])) {
                $this->memberships->requireActiveMembership(
                    $data->attributes['assignee_membership_id'],
                    lockForUpdate: true,
                );
            }
            if (isset($data->attributes['work_department_id'])) {
                $this->memberships->requireActiveDepartment(
                    $data->attributes['work_department_id'],
                    lockForUpdate: true,
                );
            }

            OptimisticLock::assert($task, $data->lockVersion, 'work_task');
            OptimisticLock::updateOrConflict(
                $task,
                $data->lockVersion,
                $data->attributes,
                'work_task',
            );
            $this->audit->record(
                'work.task.assign',
                'SUCCESS',
                $task,
                $data->attributes,
            );

            return $task->fresh();
        });
    }
}
