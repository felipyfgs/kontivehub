<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\AssociateFiscalCategoryBatchData;
use App\Enums\FiscalCoverage;
use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class AssociateFiscalCategoryBatchRequest extends AuthenticatedRequest
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
            'fiscal_category_id' => ['required', 'integer', 'exists:fiscal_categories,id'],
            'client_ids' => ['required', 'array', 'min:1', 'max:500'],
            'client_ids.*' => ['integer'],
            'coverage' => ['sometimes', 'string', Rule::enum(FiscalCoverage::class)],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function batchData(): AssociateFiscalCategoryBatchData
    {
        $data = $this->validated();

        return new AssociateFiscalCategoryBatchData(
            fiscalCategoryId: (int) $data['fiscal_category_id'],
            clientIds: array_map('intval', $data['client_ids'] ?? []),
            coverage: isset($data['coverage'])
                ? FiscalCoverage::from((string) $data['coverage'])
                : null,
        );
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
