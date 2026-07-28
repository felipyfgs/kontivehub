<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class SimplesMeiReadRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) || $this->containsTenantId($this->query->all())
            || $this->containsTenantId($this->request->all())
            || ($this->isJson()
                && $this->json() !== null
                && $this->containsTenantId($this->json()->all()))) {
            throw new HttpResponseException(response()->json([
                'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
                'code' => 'CLIENT_TENANT_ID_REJECTED',
            ], 422));
        }

        $this->prepareSimplesMeiValidation();
    }

    protected function prepareSimplesMeiValidation(): void {}

    public function clientId(): int
    {
        return (int) $this->route('client');
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Perfil não resolvido.');
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
