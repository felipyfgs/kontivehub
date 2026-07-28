<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\MailboxMessageFilters;
use App\Enums\MailboxTriageStatus;
use Illuminate\Validation\Rule;

final class ListMailboxMessagesRequest extends MailboxReadRequest
{
    protected function prepareMailboxValidation(): void
    {
        $status = $this->input('triage_status');
        if (is_string($status)) {
            $this->merge(['triage_status' => strtoupper(trim($status))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'triage_status' => [
                'sometimes',
                'string',
                Rule::enum(MailboxTriageStatus::class),
            ],
        ];
    }

    public function filters(): MailboxMessageFilters
    {
        $validated = $this->validated();

        return new MailboxMessageFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            triageStatus: isset($validated['triage_status'])
                ? (string) $validated['triage_status']
                : null,
        );
    }
}
