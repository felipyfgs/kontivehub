<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\ProxyPowerListFilterData;
use Illuminate\Validation\Rule;

final class ListProxyPowersRequest extends SerproAuthorizationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in([
                'id',
                'client_id',
                'power_code',
                'system_code',
                'status',
            ])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ];
    }

    public function toDto(): ProxyPowerListFilterData
    {
        return new ProxyPowerListFilterData(
            clientId: $this->has('client_id')
                ? (int) $this->validated('client_id')
                : null,
            perPage: (int) ($this->validated('per_page') ?? 50),
            sort: (string) ($this->validated('sort') ?? 'id'),
            direction: (string) ($this->validated('direction') ?? 'desc'),
        );
    }
}
