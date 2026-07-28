<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\SimplesMeiCatalogData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SimplesMeiCatalogData */
final class SimplesMeiCatalogResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SimplesMeiCatalogData $data */
        $data = $this->resource;

        return [
            'data' => $data->operations,
            'module' => $data->module,
            'module_enabled' => $data->moduleEnabled,
            'mutating_enabled' => $data->mutatingEnabled,
        ];
    }
}
