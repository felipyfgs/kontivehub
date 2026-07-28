<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\EnqueueFiscalMonitoringRunData;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class EnqueueFiscalMonitoringRunRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalSyncTrigger,
            );
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
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
        return [
            'client_id' => ['required', 'integer'],
            'system_code' => ['required', 'string', 'max:40'],
            'service_code' => ['required', 'string', 'max:80'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function enqueueData(): EnqueueFiscalMonitoringRunData
    {
        $data = $this->validated();

        return new EnqueueFiscalMonitoringRunData(
            clientId: (int) $data['client_id'],
            systemCode: strtoupper((string) $data['system_code']),
            serviceCode: strtoupper((string) $data['service_code']),
            operationCode: strtoupper((string) ($data['operation_code'] ?? 'MONITOR')),
            correlationId: isset($data['correlation_id'])
                ? (string) $data['correlation_id']
                : null,
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
