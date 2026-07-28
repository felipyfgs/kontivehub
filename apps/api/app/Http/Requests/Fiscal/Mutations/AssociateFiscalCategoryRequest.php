<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\AssociateFiscalCategoryData;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;
use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssociateFiscalCategoryRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        $role = app(CurrentTenant::class)->role();

        return $role !== null
            && in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true);
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'fiscal_category_id' => ['required', 'integer', 'exists:fiscal_categories,id'],
            'coverage' => ['sometimes', 'string', Rule::enum(FiscalCoverage::class)],
            'status' => ['sometimes', 'string', Rule::enum(FiscalLinkStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function associateData(): AssociateFiscalCategoryData
    {
        $data = $this->validated();

        return new AssociateFiscalCategoryData(
            clientId: (int) $data['client_id'],
            fiscalCategoryId: (int) $data['fiscal_category_id'],
            coverage: isset($data['coverage'])
                ? FiscalCoverage::from((string) $data['coverage'])
                : null,
            status: isset($data['status'])
                ? FiscalLinkStatus::from((string) $data['status'])
                : FiscalLinkStatus::Active,
            notes: $data['notes'] ?? null,
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
