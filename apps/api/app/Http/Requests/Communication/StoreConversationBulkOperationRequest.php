<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ConversationBulkOperationAdmissionData;
use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationStatus;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

final class StoreConversationBulkOperationRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        if ($this->filled('idempotency_key')) {
            // body already provided
        } else {
            $header = $this->header('Idempotency-Key');
            if (is_string($header) && trim($header) !== '') {
                $this->merge(['idempotency_key' => trim($header)]);
            }
        }

        if ($this->has('action') && is_string($this->input('action'))) {
            $this->merge(['action' => strtoupper(trim($this->string('action')->toString()))]);
        }

        if ($this->has('params.status') && is_string($this->input('params.status'))) {
            $this->merge([
                'params' => array_merge(
                    is_array($this->input('params')) ? $this->input('params') : [],
                    ['status' => strtoupper(trim((string) $this->input('params.status')))],
                ),
            ]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        $access = app(CommunicationAccess::class);
        if (! $access->canView($actor)) {
            return false;
        }

        $action = ConversationBulkAction::tryFrom(
            strtoupper(trim((string) $this->input('action', ''))),
        );
        if ($action === null) {
            return true;
        }

        if (! $action->requiresReplyPermission()) {
            return true;
        }

        // Reply is re-checked per inbox at admission; here require any reply capability via manage or view+.
        // Final gate is per-item inbox reply during admission/processing.
        return $access->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:80'],
            'action' => ['required', 'string', Rule::enum(ConversationBulkAction::class)],
            'params' => ['sometimes', 'array'],
            'params.status' => ['sometimes', 'string', Rule::enum(ConversationStatus::class)],
            'params.snoozed_until' => ['sometimes', 'nullable', 'date', 'after:now'],
            'params.assignee_membership_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'params.work_department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'params.label_ids' => ['sometimes', 'array', 'min:1', 'max:50'],
            'params.label_ids.*' => ['integer', 'min:1'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.conversation_id' => ['required', 'integer', 'min:1', 'distinct'],
            'items.*.lock_version' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.through_message_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.read_state_version' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $action = ConversationBulkAction::tryFrom(
                strtoupper(trim((string) $this->input('action', ''))),
            );
            if ($action === null) {
                return;
            }

            $params = is_array($this->input('params')) ? $this->input('params') : [];
            $items = is_array($this->input('items')) ? $this->input('items') : [];

            match ($action) {
                ConversationBulkAction::SetStatus => $this->assertSetStatusParams($validator, $params),
                ConversationBulkAction::SetAssignee => $this->assertNullableParamPresent(
                    $validator,
                    $params,
                    'assignee_membership_id',
                ),
                ConversationBulkAction::SetDepartment => $this->assertNullableParamPresent(
                    $validator,
                    $params,
                    'work_department_id',
                ),
                ConversationBulkAction::AddLabels,
                ConversationBulkAction::RemoveLabels => $this->assertLabelParams($validator, $params),
                ConversationBulkAction::MarkRead,
                ConversationBulkAction::MarkUnread => null,
            };

            foreach ($items as $index => $item) {
                if (! is_array($item)) {
                    continue;
                }
                if ($action->requiresLockVersion() && ! isset($item['lock_version'])) {
                    $validator->errors()->add(
                        "items.{$index}.lock_version",
                        'lock_version é obrigatório para esta ação.',
                    );
                }
                if ($action->requiresThroughMessageId() && ! isset($item['through_message_id'])) {
                    $validator->errors()->add(
                        "items.{$index}.through_message_id",
                        'through_message_id é obrigatório para MARK_READ.',
                    );
                }
                if ($action->requiresReadStateVersion() && ! isset($item['read_state_version'])) {
                    $validator->errors()->add(
                        "items.{$index}.read_state_version",
                        'read_state_version é obrigatório para MARK_UNREAD.',
                    );
                }
            }
        });
    }

    public function admissionData(): ConversationBulkOperationAdmissionData
    {
        $validated = $this->validated();
        $params = is_array($validated['params'] ?? null) ? $validated['params'] : [];
        $items = [];
        foreach ($validated['items'] as $item) {
            $row = [
                'conversation_id' => (int) $item['conversation_id'],
            ];
            if (array_key_exists('lock_version', $item)) {
                $row['lock_version'] = $item['lock_version'] !== null
                    ? (int) $item['lock_version']
                    : null;
            }
            if (array_key_exists('through_message_id', $item)) {
                $row['through_message_id'] = $item['through_message_id'] !== null
                    ? (int) $item['through_message_id']
                    : null;
            }
            if (array_key_exists('read_state_version', $item)) {
                $row['read_state_version'] = $item['read_state_version'] !== null
                    ? (int) $item['read_state_version']
                    : null;
            }
            $items[] = $row;
        }

        return new ConversationBulkOperationAdmissionData(
            actor: $this->actor(),
            action: ConversationBulkAction::from((string) $validated['action']),
            params: $this->sanitizeParams(
                ConversationBulkAction::from((string) $validated['action']),
                $params,
            ),
            items: $items,
            idempotencyKey: (string) $validated['idempotency_key'],
        );
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function sanitizeParams(ConversationBulkAction $action, array $params): array
    {
        return match ($action) {
            ConversationBulkAction::SetStatus => array_filter([
                'status' => isset($params['status'])
                    ? strtoupper((string) $params['status'])
                    : null,
                'snoozed_until' => $params['snoozed_until'] ?? null,
            ], static fn ($value) => $value !== null),
            ConversationBulkAction::SetAssignee => [
                'assignee_membership_id' => array_key_exists('assignee_membership_id', $params)
                    ? ($params['assignee_membership_id'] !== null
                        ? (int) $params['assignee_membership_id']
                        : null)
                    : null,
            ],
            ConversationBulkAction::SetDepartment => [
                'work_department_id' => array_key_exists('work_department_id', $params)
                    ? ($params['work_department_id'] !== null
                        ? (int) $params['work_department_id']
                        : null)
                    : null,
            ],
            ConversationBulkAction::AddLabels,
            ConversationBulkAction::RemoveLabels => [
                'label_ids' => $this->sortedUniqueIds($params['label_ids'] ?? []),
            ],
            ConversationBulkAction::MarkRead,
            ConversationBulkAction::MarkUnread => [],
        };
    }

    /** @param array<string, mixed> $params */
    private function assertSetStatusParams(Validator $validator, array $params): void
    {
        if (! isset($params['status']) || ConversationStatus::tryFrom((string) $params['status']) === null) {
            $validator->errors()->add('params.status', 'status é obrigatório para SET_STATUS.');
        }
        if (($params['status'] ?? null) === ConversationStatus::Snoozed->value
            && empty($params['snoozed_until'])) {
            $validator->errors()->add(
                'params.snoozed_until',
                'snoozed_until é obrigatório quando status é SNOOZED.',
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function assertLabelParams(Validator $validator, array $params): void
    {
        $labelIds = $params['label_ids'] ?? null;
        if (! is_array($labelIds) || $labelIds === []) {
            $validator->errors()->add(
                'params.label_ids',
                'label_ids é obrigatório para ações de rótulo.',
            );
        }
    }

    /** @param array<string, mixed> $params */
    private function assertNullableParamPresent(
        Validator $validator,
        array $params,
        string $key,
    ): void {
        if (! array_key_exists($key, $params)) {
            $validator->errors()->add("params.{$key}", "{$key} deve estar presente.");
        }
    }

    /** @param array<int, mixed> $ids @return list<int> */
    private function sortedUniqueIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $ids)));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        if (! $this->filled('idempotency_key') && ! is_string($this->header('Idempotency-Key'))) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['Idempotency-Key é obrigatória.'],
            ]);
        }

        parent::failedValidation($validator);
    }
}
