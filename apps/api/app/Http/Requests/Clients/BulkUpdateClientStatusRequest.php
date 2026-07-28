<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\BulkClientStatusData;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\ClientPolicy;
use Illuminate\Validation\ValidationException;

final class BulkUpdateClientStatusRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => ['tenant_id não é aceito; use o Tenant corrente.'],
            ]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(ClientPolicy::class)->viewAny($actor);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'is_active' => ['required', 'boolean'],
            'inactive_reason' => ['nullable', 'required_if:is_active,false', 'string', 'max:1000'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function toDto(): BulkClientStatusData
    {
        $data = $this->validated();
        $isActive = (bool) $data['is_active'];

        return new BulkClientStatusData(
            clientIds: array_values(array_map('intval', $data['client_ids'])),
            isActive: $isActive,
            inactiveReason: $isActive ? null : trim((string) $data['inactive_reason']),
        );
    }
}
