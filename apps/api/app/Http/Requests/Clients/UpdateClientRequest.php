<?php

namespace App\Http\Requests\Clients;

use App\DTO\Clients\ClientUpdateData;
use App\Enums\TaxRegimeCode;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\Client;
use App\Models\User;
use App\Policies\ClientPolicy;
use App\Support\CurrentTenant;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateClientRequest extends AuthenticatedRequest
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
        $client = $this->route('client');

        return $actor instanceof User
            && $client instanceof Client
            && app(ClientPolicy::class)->update($actor, $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->resolve()?->id;

        return [
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'inactive_reason' => ['nullable', 'string', 'max:1000'],
            'legal_nature_code' => ['nullable', 'string', 'max:16'],
            'legal_nature_name' => ['nullable', 'string', 'max:255'],
            'company_size_code' => ['nullable', 'string', 'max:16'],
            'company_size_name' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['nullable', 'string', Rule::in(TaxRegimeCode::currentProjectionValues())],
            'work_department_id' => [
                'nullable',
                'integer',
                Rule::exists('work_departments', 'id')->where(
                    fn ($query) => $tenantId
                        ? $query->where('tenant_id', $tenantId)->where('is_active', true)
                        : $query->whereRaw('1 = 0')
                ),
            ],
            // imutáveis / proibidos
            'root_cnpj' => ['prohibited'],
            'cnpj' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'registration_source' => ['prohibited'],
            'registration_refreshed_at' => ['prohibited'],
        ];
    }

    public function toDto(): ClientUpdateData
    {
        return new ClientUpdateData($this->validated());
    }
}
