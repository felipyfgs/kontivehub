<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\ManualConsultExecuteData;
use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ExecuteManualConsultRequest extends AuthenticatedRequest
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
            'action_id' => ['required', 'string', 'max:160'],
            'client_id' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
            'params' => ['sometimes', 'array'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function executeData(): ManualConsultExecuteData
    {
        $data = $this->validated();

        return new ManualConsultExecuteData(
            clientId: (int) $data['client_id'],
            actionId: (string) $data['action_id'],
            confirmed: true,
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
        );
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Sem permissão de sincronização.');
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
