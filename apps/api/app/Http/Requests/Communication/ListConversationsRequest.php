<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ConversationFiltersData;
use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class ListConversationsRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        if ($this->query->has('unassigned')) {
            $this->merge(['unassigned' => $this->boolean('unassigned')]);
        }
        if ($this->query->has('unread')) {
            $this->merge(['unread' => $this->boolean('unread')]);
        }
        if ($this->query->has('snapshot')) {
            $this->merge(['snapshot' => $this->boolean('snapshot')]);
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
        if ($this->query->has('snapshot_token') && is_string($this->query('snapshot_token'))) {
            $token = trim($this->string('snapshot_token')->toString());
            $this->merge(['snapshot_token' => $token !== '' ? $token : null]);
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
        $snapshotToken = $this->input('snapshot_token');
        $usesSnapshotToken = is_string($snapshotToken) && trim($snapshotToken) !== '';

        return $actor instanceof User
            && ($usesSnapshotToken || app(Access::class)->canView($actor));
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
            'snapshot' => ['sometimes', 'boolean'],
            'snapshot_token' => ['sometimes', 'nullable', 'string', 'max:128'],
            'q' => ['sometimes', 'nullable', 'string', 'max:120'],
            'label_ids' => ['sometimes', 'array', 'max:50'],
            'label_ids.*' => ['integer', 'min:1'],
            'sort_by' => ['sometimes', 'string', Rule::in(ConversationListSort::values())],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $createSnapshot = $this->boolean('snapshot');
            $hasSnapshotToken = is_string($this->input('snapshot_token'))
                && trim((string) $this->input('snapshot_token')) !== '';
            $page = max(1, (int) $this->input('page', 1));

            if ($createSnapshot && ($hasSnapshotToken || $page !== 1 || ! $this->boolean('unread'))) {
                $validator->errors()->add(
                    'snapshot',
                    'snapshot=true exige unread=true, primeira página e ausência de snapshot_token.',
                );
            }
            if ($hasSnapshotToken && ! $this->boolean('unread')) {
                $validator->errors()->add(
                    'snapshot_token',
                    'snapshot_token exige unread=true.',
                );
            }
        }];
    }

    public function filters(): ConversationFiltersData
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

        return new ConversationFiltersData(
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
            createSnapshot: (bool) ($validated['snapshot'] ?? false),
            snapshotToken: isset($validated['snapshot_token'])
                ? (string) $validated['snapshot_token']
                : null,
        );
    }
}
