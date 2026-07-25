<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;
use Illuminate\Validation\Rule;

final class GenerateMeiDasRequest extends MeiPublicOperationRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('idempotency_key')) {
            $key = trim((string) $this->header('Idempotency-Key', ''));
            if ($key !== '') {
                $this->merge(['idempotency_key' => $key]);
            }
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'competencies' => ['required', 'array', 'min:1', 'max:12'],
            'competencies.*' => ['required', 'string', 'distinct', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'due_date' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:today'],
            'output_format' => ['required', 'string', Rule::in(['PDF', 'BARCODE'])],
            'confirmed' => ['required', 'accepted'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:160'],
            'preflight_token' => ['required', 'string', 'max:64'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'office_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
