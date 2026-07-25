<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Module\ModuleOverviewDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModuleOverviewDto
 */
class FiscalModuleOverviewResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ModuleOverviewDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
