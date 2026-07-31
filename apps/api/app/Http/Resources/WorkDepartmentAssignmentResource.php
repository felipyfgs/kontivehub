<?php

namespace App\Http\Resources;

use App\DTO\Work\DepartmentAssignmentResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DepartmentAssignmentResult */
final class WorkDepartmentAssignmentResource extends JsonResource
{
    /** @return array{membership_id: int, work_department_id: int} */
    public function toArray(Request $request): array
    {
        /** @var DepartmentAssignmentResult $result */
        $result = $this->resource;

        return [
            'membership_id' => $result->membershipId,
            'work_department_id' => $result->workDepartmentId,
        ];
    }
}
