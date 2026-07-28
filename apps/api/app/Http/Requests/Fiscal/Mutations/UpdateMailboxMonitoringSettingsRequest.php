<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\UpdateMailboxMonitoringSettingsData;
use App\Enums\MailboxMonitoringMode;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

final class UpdateMailboxMonitoringSettingsRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User
            || ! app(TenantAuthorization::class)->allows($actor, TenantPermission::TenantSettingsManage)) {
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
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:ECONOMICO,DIARIO_COMPLETO'],
            'daily_time' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'in:America/Sao_Paulo'],
            'reconciliation_days' => ['sometimes', 'integer', 'between:1,365'],
            'auto_detail_limit' => ['sometimes', 'integer', 'between:0,100'],
            'monthly_budget_micros' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function settingsData(): UpdateMailboxMonitoringSettingsData
    {
        $data = $this->validated();

        return new UpdateMailboxMonitoringSettingsData(
            enabled: array_key_exists('enabled', $data) ? (bool) $data['enabled'] : null,
            mode: isset($data['mode']) ? MailboxMonitoringMode::from((string) $data['mode']) : null,
            dailyTime: isset($data['daily_time']) ? (string) $data['daily_time'] : null,
            timezone: isset($data['timezone']) ? (string) $data['timezone'] : null,
            reconciliationDays: isset($data['reconciliation_days'])
                ? (int) $data['reconciliation_days']
                : null,
            autoDetailLimit: isset($data['auto_detail_limit'])
                ? (int) $data['auto_detail_limit']
                : null,
            monthlyBudgetMicros: array_key_exists('monthly_budget_micros', $data)
                && $data['monthly_budget_micros'] !== null
                    ? (int) $data['monthly_budget_micros']
                    : null,
            monthlyBudgetMicrosProvided: array_key_exists('monthly_budget_micros', $data),
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão para operar o monitoramento da Caixa Postal.');
    }
}
