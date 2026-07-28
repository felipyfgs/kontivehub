<?php

namespace App\Http\Requests\Tenant;

final class ViewTenantAutXmlRequest extends TenantAutXmlRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ];
    }

    public function perPage(): int
    {
        return $this->integer('per_page', 25);
    }
}
