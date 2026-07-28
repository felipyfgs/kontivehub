<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\MailboxAlertFilters;

final class ListMailboxAlertsRequest extends MailboxReadRequest
{
    protected function prepareMailboxValidation(): void
    {
        $value = $this->input('active_only');
        if (! is_string($value)) {
            return;
        }

        $normalized = strtolower(trim($value));
        if (in_array($normalized, ['true', 'false'], true)) {
            $this->merge(['active_only' => $normalized === 'true']);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'active_only' => ['sometimes', 'boolean'],
        ];
    }

    public function filters(): MailboxAlertFilters
    {
        $validated = $this->validated();

        return new MailboxAlertFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            activeOnly: array_key_exists('active_only', $validated)
                ? $this->boolean('active_only')
                : true,
        );
    }
}
