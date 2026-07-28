<?php

namespace App\Http\Resources;

use App\Models\WorkDepartment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkDepartment */
final class WorkDepartmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkDepartment $department */
        $department = $this->resource;

        return [
            'id' => $department->id,
            'name' => $department->name,
            'code' => $department->code,
            'color' => $department->color,
            'is_active' => $department->is_active,
            'created_at' => $department->created_at?->toIso8601String(),
            'updated_at' => $department->updated_at?->toIso8601String(),
        ];
    }
}
