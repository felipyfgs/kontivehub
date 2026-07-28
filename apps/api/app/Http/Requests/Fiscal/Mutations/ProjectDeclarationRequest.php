<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\ProjectDeclarationData;

final class ProjectDeclarationRequest extends DeclarationHubWriteRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'max:20'],
            'obligation_code' => ['sometimes', 'nullable', 'string', 'max:60'],
            'period_year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'period_month' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:12'],
            'all' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function projectData(): ProjectDeclarationData
    {
        $data = $this->validated();

        return new ProjectDeclarationData(
            clientId: (int) $data['client_id'],
            periodKey: (string) $data['period_key'],
            obligationCode: isset($data['obligation_code'])
                ? (string) $data['obligation_code']
                : null,
            periodYear: isset($data['period_year'])
                ? (int) $data['period_year']
                : null,
            periodMonth: isset($data['period_month'])
                ? (int) $data['period_month']
                : null,
            all: (bool) ($data['all'] ?? false),
        );
    }
}
