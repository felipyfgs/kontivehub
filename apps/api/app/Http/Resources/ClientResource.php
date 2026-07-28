<?php

namespace App\Http\Resources;

use App\Enums\TaxRegimeCode;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Client */
class ClientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Client $client */
        $client = $this->resource;
        $taxRegime = is_string($client->tax_regime)
            ? TaxRegimeCode::tryFrom($client->tax_regime)
            : null;

        $payload = [
            'id' => $client->id,
            'tenant_id' => $client->tenant_id,
            'legal_name' => $client->legal_name,
            'display_name' => $client->display_name,
            'root_cnpj' => $client->root_cnpj,
            'legal_nature_code' => $client->legal_nature_code,
            'legal_nature_name' => $client->legal_nature_name,
            'company_size_code' => $client->company_size_code,
            'company_size_name' => $client->company_size_name,
            'capital_social' => $client->capital_social !== null
                ? (string) $client->capital_social
                : null,
            'responsible_qualification_code' => $client->responsible_qualification_code,
            'responsible_qualification_name' => $client->responsible_qualification_name,
            'tax_regime' => $taxRegime?->value,
            'tax_regime_label' => $taxRegime?->label(),
            'work_department_id' => $client->work_department_id,
            'work_department' => $client->relationLoaded('workDepartment') && $client->workDepartment
                ? [
                    'id' => (int) $client->workDepartment->id,
                    'name' => (string) $client->workDepartment->name,
                    'code' => (string) $client->workDepartment->code,
                ]
                : null,
            'categories' => $client->relationLoaded('categories')
                ? $client->categories->map(static fn ($category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'color' => (string) $category->color,
                    'is_active' => (bool) $category->is_active,
                ])->values()->all()
                : [],
            'notes' => $client->notes,
            'is_active' => $client->is_active,
            'inactive_reason' => $client->inactive_reason,
            'registration_source' => $client->registration_source?->value ?? $client->registration_source,
            'registration_refreshed_at' => $client->registration_refreshed_at?->toIso8601String(),
            'establishments_count' => $client->establishments_count ?? null,
            'created_at' => $client->created_at?->toIso8601String(),
            'updated_at' => $client->updated_at?->toIso8601String(),
        ];

        return $payload;
    }
}
