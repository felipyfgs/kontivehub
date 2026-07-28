<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\MitLocalAssessmentFilters;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListMitLocalAssessmentsRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'year' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
        ];
    }

    public function filters(): MitLocalAssessmentFilters
    {
        $validated = $this->validated();

        return new MitLocalAssessmentFilters(
            clientId: (int) $validated['client_id'],
            year: isset($validated['year'])
                ? (int) $validated['year']
                : null,
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
