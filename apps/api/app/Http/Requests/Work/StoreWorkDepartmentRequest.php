<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkDepartmentData;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;

final class StoreWorkDepartmentRequest extends WorkRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('create', WorkDepartment::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('work_departments', 'name')
                    ->where('tenant_id', $tenantId),
            ],
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9_\-]+$/',
                Rule::unique('work_departments', 'code')
                    ->where('tenant_id', $tenantId),
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

    public function department(): WorkDepartmentData
    {
        return new WorkDepartmentData($this->validated());
    }
}
