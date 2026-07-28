<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\WorkDepartmentMembershipData;
use App\Models\User;
use App\Models\WorkDepartment;

final class AssignWorkDepartmentMembershipRequest extends WorkRequest
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

    public function membership(): WorkDepartmentMembershipData
    {
        return new WorkDepartmentMembershipData(
            $this->integer('membership_id'),
        );
    }
}
