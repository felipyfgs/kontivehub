<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\ConfirmMailboxSyncData;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class ConfirmMailboxSyncRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User
            || ! app(TenantAuthorization::class)->allows($actor, TenantPermission::FiscalSyncTrigger)) {
            return false;
        }

        $tenant = app(CurrentTenant::class)->tenant();

        return FeatureFlags::isModuleEnabled('mailbox', (int) $tenant->id);
    }

    protected function prepareForValidation(): void
    {
        if ($this->exists('tenant_id')
            || $this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => 'tenant_id é derivado do tenant autenticado e não pode ser enviado.',
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
            'force_all' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ];
    }

    public function syncData(): ConfirmMailboxSyncData
    {
        $data = $this->validated();

        return new ConfirmMailboxSyncData(
            forceAll: (bool) ($data['force_all'] ?? false),
            idempotencyKey: (string) $data['idempotency_key'],
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão para operar o monitoramento da Caixa Postal.');
    }
}
