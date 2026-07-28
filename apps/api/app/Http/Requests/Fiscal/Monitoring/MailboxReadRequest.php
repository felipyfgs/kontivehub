<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Validation\ValidationException;

abstract class MailboxReadRequest extends AuthenticatedRequest
{
    private string $authorizationMessage = 'Sem permissão para consultar a Caixa Postal.';

    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User
            || ! app(TenantAuthorization::class)->allows(
                $actor,
                $this->requiredPermission(),
            )) {
            $this->authorizationMessage = $this->permissionDeniedMessage();

            return false;
        }

        $tenant = app(CurrentTenant::class)->tenant();
        if ($tenant === null
            || ! FeatureFlags::isModuleEnabled('mailbox', (int) $tenant->id)) {
            $this->authorizationMessage = 'Módulo Caixa Postal não disponível.';

            return false;
        }

        return true;
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) || $this->containsTenantId($this->query->all())
            || $this->containsTenantId($this->request->all())) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }

        $this->prepareMailboxValidation();
    }

    protected function requiredPermission(): TenantPermission
    {
        return TenantPermission::OperationsView;
    }

    protected function permissionDeniedMessage(): string
    {
        return 'Sem permissão para consultar a Caixa Postal.';
    }

    protected function prepareMailboxValidation(): void {}

    protected function failedAuthorization(): void
    {
        abort(403, $this->authorizationMessage);
    }

    /** @param array<array-key, mixed> $payload */
    private function containsTenantId(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'tenant_id') === 0) {
                return true;
            }
            if (is_array($value) && $this->containsTenantId($value)) {
                return true;
            }
        }

        return false;
    }
}
