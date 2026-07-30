<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationConversationFiltersData;
use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;

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
        if ($this->query->has('sort_by') && is_string($this->query('sort_by'))) {
            $this->merge(['sort_by' => strtolower(trim($this->string('sort_by')->toString()))]);
        }

        // Normaliza strings numéricas; não filtra inválidos (label_ids.* rejeita com 422).
        $labelIds = $this->query('label_ids');
        if (is_array($labelIds)) {
            $this->merge(['label_ids' => array_values(array_map(
                static fn ($id): mixed => is_int($id)
                    || (is_string($id) && ctype_digit($id))
                    ? (int) $id
                    : $id,
                $labelIds,
            ))]);
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
            'contact_id' => ['sometimes', 'integer', 'min:1'],
            'unassigned' => ['sometimes', 'boolean'],
            'unread' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'label_ids' => ['sometimes', 'array', 'max:50'],
            'label_ids.*' => ['integer', 'min:1'],
            'sort_by' => ['sometimes', 'string', Rule::in(ConversationListSort::values())],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): CommunicationConversationFiltersData
    {
        $validated = $this->validated();
        $search = trim((string) ($validated['q'] ?? ''));
        $status = strtoupper(trim((string) ($validated['status'] ?? '')));
        $labelIds = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $validated['label_ids'] ?? [],
        )));
        $sortBy = isset($validated['sort_by'])
            ? ConversationListSort::from((string) $validated['sort_by'])
            : null;

        return new CommunicationConversationFiltersData(
            inboxId: isset($validated['inbox_id']) ? (int) $validated['inbox_id'] : null,
            status: ConversationStatus::tryFrom($status),
            assigneeMembershipId: isset($validated['assignee_membership_id'])
                ? (int) $validated['assignee_membership_id']
                : null,
            workDepartmentId: isset($validated['work_department_id'])
                ? (int) $validated['work_department_id']
                : null,
            contactId: isset($validated['contact_id']) ? (int) $validated['contact_id'] : null,
            unassigned: (bool) ($validated['unassigned'] ?? false),
            unreadOnly: (bool) ($validated['unread'] ?? false),
            search: $search !== '' ? $search : null,
            labelIds: $labelIds,
            sortBy: $sortBy,
            perPage: (int) ($validated['per_page'] ?? 30),
            page: (int) ($validated['page'] ?? 1),
        );
    }
}
