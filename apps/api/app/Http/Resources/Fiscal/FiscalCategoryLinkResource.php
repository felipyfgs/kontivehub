<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TenantFiscalCategoryLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantFiscalCategoryLink */
final class FiscalCategoryLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantFiscalCategoryLink $link */
        $link = $this->resource;

        return [
            'id' => $link->id,
            'tenant_id' => $link->tenant_id,
            'client_id' => $link->client_id,
            'fiscal_category_id' => $link->fiscal_category_id,
            'category_code' => $link->category?->code,
            'category_name' => $link->category?->name,
            'status' => $link->status?->value,
            'coverage' => $link->coverage?->value,
            'activated_at' => $link->activated_at?->toIso8601String(),
            'deactivated_at' => $link->deactivated_at?->toIso8601String(),
            'notes' => $link->notes,
        ];
    }
}
