<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Actions\Fiscal\FindFiscalClientAction;
use App\DTO\Fiscal\Monitoring\MonitoringModuleCommunicationReadData;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ViewMonitoringModuleCommunicationRequest extends AuthenticatedRequest
{
    private const MODULES = ['sitfis', 'fgts', 'mit'];

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalMonitoringView,
            );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function readData(): MonitoringModuleCommunicationReadData
    {
        $tenant = app(CurrentTenant::class)->tenant();
        $module = strtolower((string) $this->route('module'));
        if (! in_array($module, self::MODULES, true)) {
            abort(404, 'Módulo de comunicação desconhecido.');
        }

        $client = app(FindFiscalClientAction::class)->handle(
            $tenant,
            (int) $this->route('client'),
        );
        if ($client === null) {
            abort(404, 'Cliente não encontrado.');
        }

        return new MonitoringModuleCommunicationReadData(
            tenant: $tenant,
            client: $client,
            module: $module,
        );
    }

    protected function prepareForValidation(): void
    {
        if (! $this->clientTenantIdWasSupplied()) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422));
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Perfil não resolvido.');
    }

    private function clientTenantIdWasSupplied(): bool
    {
        return $this->attributes->getBoolean(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        )
            || $this->containsTenantIdKey($this->query->all())
            || $this->containsTenantIdKey($this->request->all())
            || ($this->isJson()
                && $this->json() !== null
                && $this->containsTenantIdKey($this->json()->all()));
    }

    /** @param array<array-key, mixed> $values */
    private function containsTenantIdKey(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'tenant_id') === 0) {
                return true;
            }
            if (is_array($value) && $this->containsTenantIdKey($value)) {
                return true;
            }
        }

        return false;
    }
}
