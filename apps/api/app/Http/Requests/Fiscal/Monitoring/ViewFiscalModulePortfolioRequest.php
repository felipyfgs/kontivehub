<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalModuleKey;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class ViewFiscalModulePortfolioRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    protected function prepareForValidation(): void
    {
        $module = FiscalModuleKey::tryFromRoute((string) $this->route('module'));
        if ($module === FiscalModuleKey::SimplesMei && $this->clientTenantIdWasSupplied()) {
            throw new HttpResponseException(response()->json([
                'message' => 'O escritório é definido pela sessão e não pode ser informado pelo cliente.',
                'code' => 'CLIENT_TENANT_ID_REJECTED',
            ], 422));
        }

        $this->query->remove('tenant_id');
        $this->request->remove('tenant_id');

        $submodule = $this->input('submodule');
        if (is_string($submodule)) {
            $this->merge(['submodule' => strtoupper(trim($submodule))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'string', 'max:255'],
            'situation' => ['sometimes', $this->stringNumericOrArrayRule()],
            'competence' => ['sometimes', 'string', 'max:20'],
            'submodule' => [
                'sometimes',
                'string',
                Rule::in($this->moduleKey()->knownSubmodules()),
            ],
            'delivery_status' => ['sometimes', $this->stringNumericOrArrayRule()],
            'sort' => ['sometimes', 'string', 'max:40'],
            'sort_direction' => ['sometimes', 'string', 'max:8'],
            'client_id' => ['sometimes', $this->stringNumericOrArrayRule()],
            'coverage' => ['sometimes', $this->stringNumericOrArrayRule()],
            'modality' => ['sometimes', $this->stringNumericOrArrayRule()],
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
            'send_status' => ['sometimes', $this->stringNumericOrArrayRule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'submodule.in' => 'Submódulo não disponível para este módulo de monitoramento.',
        ];
    }

    public function moduleKey(): FiscalModuleKey
    {
        $key = FiscalModuleKey::tryFromRoute((string) $this->route('module'));
        if ($key === null || $key === FiscalModuleKey::Dashboard) {
            abort(404, 'Módulo fiscal desconhecido.');
        }

        return $key;
    }

    public function filters(): ModulePortfolioFilters
    {
        return ModulePortfolioFilters::fromRequest($this->validated());
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
            if (is_string($key) && strtolower($key) === 'tenant_id') {
                return true;
            }
            if (is_array($value) && $this->containsTenantIdKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function stringNumericOrArrayRule(): Closure
    {
        return static function (
            string $attribute,
            mixed $value,
            Closure $fail,
        ): void {
            if (is_string($value) || is_numeric($value)) {
                return;
            }

            if (is_array($value)
                && collect($value)->every(
                    static fn (mixed $item): bool => is_string($item) || is_numeric($item),
                )) {
                return;
            }

            $fail("O campo {$attribute} possui formato inválido.");
        };
    }
}
