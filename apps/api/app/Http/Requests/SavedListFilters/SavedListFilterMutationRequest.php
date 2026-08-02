<?php

namespace App\Http\Requests\SavedListFilters;

use App\Enums\Communication\ConversationListSort;
use App\Enums\Communication\ConversationStatus;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\SavedListFilter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;

abstract class SavedListFilterMutationRequest extends AuthenticatedRequest
{
    /** @var list<string> */
    private const CONVERSATION_PAYLOAD_FIELDS = [
        'status',
        'sort_by',
        'inbox_id',
        'assignee_membership_id',
        'work_department_id',
        'label_ids',
        'unread',
        'unassigned',
    ];

    abstract protected function partial(): bool;

    protected function shouldFailOnUnknownFields(): bool
    {
        // A matriz abaixo preserva os payloads existentes e fecha apenas a nova
        // superfície de Communication de forma explícita também em produção.
        return false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $partial = $this->partial();
        $required = $partial ? 'sometimes' : 'required';
        $rules = [
            'surface' => $partial
                ? ['prohibited']
                : ['required', 'string', Rule::in(SavedListFilter::SURFACES)],
            'name' => [$required, 'string', 'max:120'],
            'visibility' => [
                $partial ? 'sometimes' : 'nullable',
                'string',
                Rule::in([
                    SavedListFilter::VISIBILITY_PERSONAL,
                    SavedListFilter::VISIBILITY_TENANT,
                ]),
            ],
            'tenant_id' => ['prohibited'],
            'schema_version' => ['prohibited'],
            'payload' => [$partial ? 'sometimes' : 'required', 'array'],
            'payload.schema_version' => ['prohibited'],
            'payload.tenant_id' => ['prohibited'],
        ];

        if ($this->surface() !== SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS) {
            return $rules;
        }

        $payloadRequired = Rule::requiredIf(fn (): bool => $this->has('payload'));

        return [
            ...$rules,
            'payload.status' => [
                $payloadRequired,
                'string',
                Rule::in(['ALL', ...array_column(ConversationStatus::cases(), 'value')]),
            ],
            'payload.sort_by' => [
                $payloadRequired,
                'string',
                Rule::in(ConversationListSort::values()),
            ],
            'payload.inbox_id' => ['sometimes', 'integer', 'min:1'],
            'payload.assignee_membership_id' => ['sometimes', 'integer', 'min:1'],
            'payload.work_department_id' => ['sometimes', 'integer', 'min:1'],
            'payload.label_ids' => ['sometimes', 'array', 'max:50'],
            'payload.label_ids.*' => ['integer', 'min:1', 'distinct'],
            'payload.unread' => ['sometimes', 'boolean'],
            'payload.unassigned' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $allowedTopLevel = $this->partial()
                ? ['name', 'visibility', 'payload']
                : ['surface', 'name', 'visibility', 'payload'];

            foreach (array_diff(array_keys($this->all()), $allowedTopLevel) as $field) {
                $validator->errors()->add($field, 'Este campo não é permitido.');
            }

            if ($this->surface() !== SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS) {
                return;
            }

            $payload = $this->input('payload');
            if (! is_array($payload)) {
                return;
            }

            foreach (array_diff(array_keys($payload), self::CONVERSATION_PAYLOAD_FIELDS) as $field) {
                $validator->errors()->add("payload.{$field}", 'Este campo não é permitido nesta superfície.');
            }
        });
    }

    public function surface(): ?string
    {
        if ($this->partial()) {
            $filter = $this->route('listFilter');

            return $filter instanceof SavedListFilter ? $filter->surface : null;
        }

        $surface = $this->input('surface');

        return is_string($surface) ? $surface : null;
    }

    /** @return array<string, mixed> */
    public function normalizedPayload(): array
    {
        $payload = $this->validated('payload', []);
        if (! is_array($payload)
            || $this->surface() !== SavedListFilter::SURFACE_COMMUNICATION_CONVERSATIONS) {
            return is_array($payload) ? $payload : [];
        }

        $normalized = [
            'status' => (string) $payload['status'],
            'sort_by' => (string) $payload['sort_by'],
        ];

        foreach (['inbox_id', 'assignee_membership_id', 'work_department_id'] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = (int) $payload[$field];
            }
        }

        if (array_key_exists('label_ids', $payload)) {
            $labelIds = array_values(array_unique(array_map(
                static fn (mixed $id): int => (int) $id,
                $payload['label_ids'],
            )));
            sort($labelIds);
            $normalized['label_ids'] = $labelIds;
        }

        foreach (['unread', 'unassigned'] as $field) {
            if (array_key_exists($field, $payload)) {
                $normalized[$field] = (bool) $payload[$field];
            }
        }

        return $normalized;
    }
}
