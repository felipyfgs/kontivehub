<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\DeclarationCatalogData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DeclarationCatalogData */
final class DeclarationCatalogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DeclarationCatalogData $data */
        $data = $this->resource;

        return [
            'obligations' => $data->obligations,
            'calendar' => $data->calendar !== null
                ? (new TaxDeadlineCalendarVersionResource(
                    $data->calendar,
                ))->resolve($request)
                : null,
            'integration_coverage' => $data->integrationCoverage,
            'operation_catalog' => $data->operationCatalog,
        ];
    }
}
