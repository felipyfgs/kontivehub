<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\DepartmentData;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;

final class UpdateDepartmentRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $department = $this->route('department');

        return $actor instanceof User
            && $department instanceof WorkDepartment
            && $actor->can('update', $department);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        /** @var WorkDepartment $department */
        $department = $this->route('department');
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => [
                'sometimes',
                'string',
                'max:120',
                Rule::unique('work_departments', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($department->id),
            ],
            'code' => [
                'sometimes',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('work_departments', 'code')
                    ->where('tenant_id', $tenantId)
                    ->ignore($department->id),
            ],
            'color' => [
                'nullable',
                'string',
                'max:16',
                'regex:/^#?[0-9A-Fa-f]{3,8}$/',
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function department(): DepartmentData
    {
        return new DepartmentData($this->validated());
    }
}
