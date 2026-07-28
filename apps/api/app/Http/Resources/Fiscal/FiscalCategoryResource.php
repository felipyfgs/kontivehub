<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalCategory */
final class FiscalCategoryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalCategory $category */
        $category = $this->resource;

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'module_key' => $category->module_key,
            'default_coverage' => $category->default_coverage?->value,
            'default_mutability' => $category->default_mutability?->value,
            'system_code' => $category->system_code,
            'service_code' => $category->service_code,
            'is_active' => $category->is_active,
            'sort_order' => $category->sort_order,
            'description' => $category->description,
        ];
    }
}
