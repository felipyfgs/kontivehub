<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Middleware\EnsureTenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;

trait RejectsClientTenantId
{
    protected function rejectClientTenantIdIfSupplied(bool $nested = true): void
    {
        $suppliedAtTopLevel = $this->attributes->get(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) === true;

        $suppliedNested = false;
        if ($nested) {
            $suppliedNested = $this->containsTenantIdKey($this->query->all())
                || $this->containsTenantIdKey($this->request->all())
                || ($this->isJson()
                    && $this->json() !== null
                    && $this->containsTenantIdKey($this->json()->all()));
        }

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return;
        }

        throw new HttpResponseException(response()->json([
            'message' => 'tenant_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_TENANT_ID_REJECTED',
        ], 422));
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
