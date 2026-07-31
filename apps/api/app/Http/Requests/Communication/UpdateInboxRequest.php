<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\InboxUpdateData;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;

final class UpdateInboxRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = $this->route('inbox');

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(Access::class)->canManage($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();
        $inbox = $this->route('inbox');
        $inboxId = $inbox instanceof CommunicationInbox ? $inbox->id : 0;

        return [
            'name' => [
                'sometimes',
                'string',
                'min:1',
                'max:120',
                Rule::unique('communication_inboxes', 'name')
                    ->ignore($inboxId)
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
            'is_enabled' => ['sometimes', 'boolean'],
            'is_default' => ['sometimes', 'boolean'],
            'work_department_id' => [
                'sometimes',
                'nullable',
                'integer',
                'min:1',
                Rule::exists('work_departments', 'id')
                    ->where(fn (Builder $query): Builder => $query
                        ->where('tenant_id', $tenantId)),
            ],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function inboxData(): InboxUpdateData
    {
        $validated = $this->validated();

        return new InboxUpdateData(
            name: isset($validated['name']) ? (string) $validated['name'] : null,
            isEnabled: array_key_exists('is_enabled', $validated)
                ? (bool) $validated['is_enabled']
                : null,
            isDefault: array_key_exists('is_default', $validated)
                ? (bool) $validated['is_default']
                : null,
            workDepartmentId: isset($validated['work_department_id'])
                ? (int) $validated['work_department_id']
                : null,
            hasWorkDepartmentId: array_key_exists('work_department_id', $validated),
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
