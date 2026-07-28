<?php

namespace App\Http\Requests\Tenant;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ViewTenantUsageRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'month' => ['sometimes', 'integer', 'between:1,12'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'sort' => [
                'sometimes',
                'string',
                Rule::in(['occurred_at', 'quantity', 'result', 'client_id', 'id']),
            ],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function year(): ?int
    {
        return $this->has('year') ? $this->integer('year') : null;
    }

    public function month(): ?int
    {
        return $this->has('month') ? $this->integer('month') : null;
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 50);
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

        $direction = $this->input('direction');
        if (is_string($direction)) {
            $this->merge(['direction' => strtolower($direction)]);
        }
    }
}
