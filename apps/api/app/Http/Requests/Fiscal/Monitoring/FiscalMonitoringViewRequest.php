<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

abstract class FiscalMonitoringViewRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalMonitoringView,
            );
    }

    final protected function prepareForValidation(): void
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

        $this->prepareFiscalMonitoringValidation();
    }

    protected function prepareFiscalMonitoringValidation(): void {}

    final protected function normalizeBooleanInput(string $key): void
    {
        $value = $this->input($key);
        if (! is_string($value)) {
            return;
        }

        $normalized = strtolower(trim($value));
        if (! in_array($normalized, ['true', 'false'], true)) {
            return;
        }

        $this->merge([$key => $normalized === 'true']);
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão para monitoramento fiscal.');
    }
}
