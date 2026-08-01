<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\InboxCreationData;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class StoreInboxRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => [
                'required',
                'string',
                'min:1',
                'max:120',
                Rule::unique('communication_inboxes', 'name')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'work_department_id' => [
                'nullable',
                'integer',
                'min:1',
                Rule::exists('work_departments', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
        ];
    }

    protected function prepareScopedValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function inboxData(): InboxCreationData
    {
        $validated = $this->validated();

        return new InboxCreationData(
            name: $validated['name'],
            isEnabled: (bool) ($validated['is_enabled'] ?? false),
            isDefault: (bool) ($validated['is_default'] ?? false),
            workDepartmentId: isset($validated['work_department_id'])
                ? (int) $validated['work_department_id']
                : null,
        );
    }
}
