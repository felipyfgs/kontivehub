<?php

namespace App\Http\Requests\Work;

use App\DTO\Work\CalendarDayData;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use App\Models\User;
use App\Models\WorkTask;
use Illuminate\Validation\Rule;

final class ListCalendarDayRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $actor->can('viewAny', WorkTask::class);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'department_id' => ['sometimes', 'nullable', 'integer'],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(TaskStatus::class)],
            'risk' => ['sometimes', 'nullable', 'string', Rule::enum(WorkRisk::class)],
        ];
    }

    public function filters(): CalendarDayData
    {
        $validated = $this->validated();

        return new CalendarDayData(
            date: $validated['date'],
            perPage: (int) ($validated['per_page'] ?? 25),
            page: (int) ($validated['page'] ?? 1),
            departmentId: $validated['department_id'] ?? null,
            assigneeMembershipId: $validated['assignee_membership_id'] ?? null,
            clientId: $validated['client_id'] ?? null,
            status: $validated['status'] ?? null,
            risk: $validated['risk'] ?? null,
        );
    }
}
