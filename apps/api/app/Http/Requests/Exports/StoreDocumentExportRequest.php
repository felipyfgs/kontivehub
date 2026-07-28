<?php

namespace App\Http\Requests\Exports;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Jobs\BuildExportZipJob;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Validation\Rule;

final class StoreDocumentExportRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, TenantPermission::ExportsCreate);
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('tenant_id');
        $this->query->remove('tenant_id');
        if ($this->isJson() && $this->json() !== null) {
            $this->json()->remove('tenant_id');
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'filters' => ['nullable', 'array'],
            'filters.export_scope' => ['sometimes', 'nullable', 'string', Rule::in(['documents', 'fiscal_portfolio'])],
            'filters.competence' => ['sometimes', 'nullable', 'string', 'max:7'],
            'filters.access_key' => ['sometimes', 'nullable', 'string', 'max:64'],
            'filters.access_keys' => ['sometimes', 'nullable', 'array', 'max:'.BuildExportZipJob::MAX_ACCESS_KEYS],
            'filters.access_keys.*' => ['string', 'max:64'],
            'filters.issuer_cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.taker_cnpj' => ['sometimes', 'nullable', 'string', 'max:20'],
            'filters.fiscal_role' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.direction' => ['sometimes', 'nullable', 'in:IN,OUT,UNKNOWN'],
            'filters.status' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.issued_from' => ['sometimes', 'nullable', 'date'],
            'filters.issued_to' => ['sometimes', 'nullable', 'date'],
            'filters.client_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.establishment_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.module_key' => ['required_if:filters.export_scope,fiscal_portfolio', 'nullable', 'string', 'max:64'],
            'filters.situation' => ['sometimes', 'nullable', 'string', 'max:32'],
            'filters.q' => ['sometimes', 'nullable', 'string', 'max:255'],
            'filters.submodule' => ['sometimes', 'nullable', 'string', 'max:64'],
            'include_events' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array{filters: array<string, mixed>, include_events: bool} */
    public function exportInput(): array
    {
        $data = $this->validated();
        $rawFilters = $data['filters'] ?? [];
        unset($rawFilters['tenant_id']);

        return [
            'filters' => is_array($rawFilters) ? $rawFilters : [],
            'include_events' => (bool) ($data['include_events'] ?? false),
        ];
    }

    protected function failedAuthorization(): void
    {
        abort(403);
    }
}
