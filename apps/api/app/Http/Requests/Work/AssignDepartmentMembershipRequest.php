<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\DepartmentMembershipData;
use App\Models\User;
use App\Models\WorkDepartment;

final class AssignDepartmentMembershipRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $department = $this->route('department');

        return $actor instanceof User
            && $department instanceof WorkDepartment
            && $actor->can('update', $department);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['membership_id' => ['required', 'integer']];
    }

    public function membership(): DepartmentMembershipData
    {
        return new DepartmentMembershipData(
            $this->integer('membership_id'),
        );
    }
}
