<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Http\Exceptions\HttpResponseException;

final class DownloadDefisArtifactRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        if ($this->clientTenantIdWasSupplied()) {
            throw new HttpResponseException(response()->json([
                'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
                'code' => 'CLIENT_TENANT_ID_REJECTED',
            ], 422));
        }
    }

    public function artifactId(): int
    {
        return (int) $this->route('artifact');
    }

    public function ensureCanView(Client $client): void
    {
        if (! app(TenantAuthorization::class)->allows(
            $this->actor(),
            TenantPermission::FiscalMonitoringView,
            $client,
        )) {
            abort(403, 'Sem permissão para monitoramento fiscal.');
        }
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
