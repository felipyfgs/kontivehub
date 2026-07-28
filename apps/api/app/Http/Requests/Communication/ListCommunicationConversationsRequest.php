<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationConversationFiltersData;
use App\Enums\Communication\ConversationStatus;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ListCommunicationConversationsRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        if ($this->query->has('unassigned')) {
            $this->merge(['unassigned' => $this->boolean('unassigned')]);
        }
        if ($this->query->has('unread')) {
            $this->merge(['unread' => $this->boolean('unread')]);
        }
        if ($this->query->has('q') && is_string($this->query('q'))) {
            $this->merge(['q' => trim($this->string('q')->toString())]);
        }
        if ($this->query->has('status') && is_string($this->query('status'))) {
            $this->merge(['status' => strtoupper(trim($this->string('status')->toString()))]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'inbox_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'assignee_membership_id' => ['sometimes', 'integer', 'min:1'],
            'work_department_id' => ['sometimes', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): CommunicationConversationFiltersData
    {
        $validated = $this->validated();
        $search = trim((string) ($validated['q'] ?? ''));
        $status = strtoupper(trim((string) ($validated['status'] ?? '')));

        return new CommunicationConversationFiltersData(
            inboxId: isset($validated['inbox_id']) ? (int) $validated['inbox_id'] : null,
            status: ConversationStatus::tryFrom($status),
            assigneeMembershipId: isset($validated['assignee_membership_id'])
                ? (int) $validated['assignee_membership_id']
                : null,
            workDepartmentId: isset($validated['work_department_id'])
                ? (int) $validated['work_department_id']
                : null,
            unassigned: (bool) ($validated['unassigned'] ?? false),
            unreadOnly: (bool) ($validated['unread'] ?? false),
            search: $search !== '' ? $search : null,
            perPage: (int) ($validated['per_page'] ?? 30),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
