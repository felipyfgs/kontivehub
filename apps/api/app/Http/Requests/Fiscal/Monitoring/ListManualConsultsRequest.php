<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\ManualConsultInventoryFilters;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListManualConsultsRequest extends AuthenticatedRequest
{
    private bool $clientResolved = false;

    private ?Client $resolvedClient = null;

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalMonitoringView,
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

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'surface_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'module_key' => ['sometimes', 'nullable', 'string', 'max:40'],
        ];
    }

    public function filters(): ManualConsultInventoryFilters
    {
        $validated = $this->validated();

        return new ManualConsultInventoryFilters(
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            surfaceKey: isset($validated['surface_key'])
                ? (string) $validated['surface_key']
                : null,
            moduleKey: isset($validated['module_key'])
                ? (string) $validated['module_key']
                : null,
        );
    }

    public function client(): ?Client
    {
        if ($this->clientResolved) {
            return $this->resolvedClient;
        }

        $this->clientResolved = true;
        $clientId = $this->filters()->clientId;
        if ($clientId === null) {
            return null;
        }

        $tenant = app(CurrentTenant::class)->tenant();
        $this->resolvedClient = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->whereKey($clientId)
            ->first();

        return $this->resolvedClient;
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
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
