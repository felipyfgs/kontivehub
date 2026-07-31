<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\SerproEligibilityData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class EvaluateTenantSerproEligibilityRequest extends TenantSerproAuthorizationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'module' => ['nullable', 'string', 'max:40'],
        ];
    }

    public function toDto(): SerproEligibilityData
    {
        return new SerproEligibilityData(
            environment: $this->environment(),
            clientId: (int) $this->validated('client_id'),
            solutionCode: (string) $this->validated('solution_code'),
            serviceCode: (string) $this->validated('service_code'),
            operationCode: (string) $this->validated('operation_code'),
            module: $this->validated('module'),
        );
    }
}
