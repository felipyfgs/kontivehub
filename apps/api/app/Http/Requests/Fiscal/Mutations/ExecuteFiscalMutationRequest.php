<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\FiscalMutationExecuteData;
use App\Enums\TenantPermission;

final class ExecuteFiscalMutationRequest extends FiscalMutationRequest
{
    protected function prepareFiscalMutationValidation(): void
    {
        if ($this->filled('idempotency_key')) {
            return;
        }

        $headerIdempotency = $this->header('Idempotency-Key');
        if (is_string($headerIdempotency) && trim($headerIdempotency) !== '') {
            $this->merge(['idempotency_key' => $headerIdempotency]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'operation_key' => [
                'required',
                'string',
                'max:160',
                'regex:/^[a-z0-9_]+(?:\.[a-z0-9_]+)+$/',
            ],
            'competence_period_key' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'preflight_token' => ['required', 'string', 'max:64'],
            'environment' => ['nullable', 'string', 'max:20'],
            'module' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function executeData(): FiscalMutationExecuteData
    {
        $data = $this->validated();

        return new FiscalMutationExecuteData(
            clientId: (int) $data['client_id'],
            solutionCode: (string) $data['solution_code'],
            serviceCode: (string) $data['service_code'],
            operationCode: (string) $data['operation_code'],
            operationKey: (string) $data['operation_key'],
            confirmationPhrase: (string) $data['confirmation_phrase'],
            confirmed: (bool) $data['confirmed'],
            competencePeriodKey: isset($data['competence_period_key'])
                ? (string) $data['competence_period_key']
                : null,
            idempotencyKey: (string) $data['idempotency_key'],
            preflightToken: (string) $data['preflight_token'],
            environment: isset($data['environment'])
                ? (string) $data['environment']
                : null,
            module: isset($data['module'])
                ? (string) $data['module']
                : null,
            payload: is_array($data['payload'] ?? null)
                ? $data['payload']
                : [],
        );
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
