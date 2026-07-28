<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\MonitoringMembershipData;
use App\Enums\FiscalModuleKey;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ManageMonitoringMembershipRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::ClientsManage,
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
            'module' => ['required', 'string', Rule::in(FiscalModuleKey::values())],
            'submodule' => ['nullable', 'string', 'max:64'],
            'client_ids' => ['required', 'array', 'min:1', 'max:200'],
            'client_ids.*' => ['integer', 'min:1'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function membershipData(): MonitoringMembershipData
    {
        $data = $this->validated();
        $module = FiscalModuleKey::tryFromRoute((string) $data['module'])
            ?? FiscalModuleKey::tryFrom((string) $data['module']);

        return new MonitoringMembershipData(
            module: $module,
            submodule: isset($data['submodule']) ? (string) $data['submodule'] : null,
            clientIds: array_map('intval', $data['client_ids'] ?? []),
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
