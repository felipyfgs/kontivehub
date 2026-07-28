<?php

namespace App\Services\Work;

use App\DTO\Work\WorkDepartmentAssignmentResult;
use App\DTO\Work\WorkDepartmentData;
use App\DTO\Work\WorkDepartmentMembershipData;
use App\Exceptions\WorkDepartmentApiException;
use App\Models\TenantMembership;
use App\Models\WorkDepartment;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class WorkDepartmentService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private AuditLogger $audit,
    ) {}

    public function create(WorkDepartmentData $data): WorkDepartment
    {
        return DB::transaction(function () use ($data): WorkDepartment {
            $attributes = $data->attributes;
            $department = WorkDepartment::query()->create([
                'tenant_id' => $this->currentTenant->id(),
                'name' => $attributes['name'],
                'code' => mb_strtoupper($attributes['code']),
                'color' => $attributes['color'] ?? null,
                'is_active' => $attributes['is_active'] ?? true,
            ]);
            $this->audit->record(
                'work.department.create',
                'SUCCESS',
                $department,
            );

            return $department;
        });
    }

    public function update(
        WorkDepartment $department,
        WorkDepartmentData $data,
    ): WorkDepartment {
        return DB::transaction(function () use ($department, $data): WorkDepartment {
            $attributes = $data->attributes;
            if (isset($attributes['code'])) {
                $attributes['code'] = mb_strtoupper($attributes['code']);
            }

            $department->fill($attributes)->save();
            $this->audit->record(
                'work.department.update',
                'SUCCESS',
                $department,
                ['fields' => array_keys($attributes)],
            );

            return $department->fresh();
        });
    }

    public function assignMembership(
        WorkDepartment $department,
        WorkDepartmentMembershipData $data,
    ): WorkDepartmentAssignmentResult {
        return DB::transaction(function () use ($department, $data): WorkDepartmentAssignmentResult {
            $lockedDepartment = WorkDepartment::query()
                ->whereKey($department->id)
                ->where('tenant_id', $this->currentTenant->id())
                ->lockForUpdate()
                ->firstOrFail();
            if (! $lockedDepartment->is_active) {
                throw WorkDepartmentApiException::inactive();
            }

            $membership = TenantMembership::query()
                ->whereKey($data->membershipId)
                ->where('tenant_id', $this->currentTenant->id())
                ->where('is_active', true)
                ->lockForUpdate()
                ->firstOrFail();
            $membership->forceFill([
                'work_department_id' => $lockedDepartment->id,
            ])->save();
            $this->audit->record(
                'work.department.assign_membership',
                'SUCCESS',
                $lockedDepartment,
                ['membership_id' => $membership->id],
            );

            return new WorkDepartmentAssignmentResult(
                membershipId: (int) $membership->id,
                workDepartmentId: (int) $lockedDepartment->id,
            );
        });
    }
}
