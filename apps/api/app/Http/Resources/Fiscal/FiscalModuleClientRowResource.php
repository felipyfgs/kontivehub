<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Module\ModuleClientRowDto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ModuleClientRowDto
 */
class FiscalModuleClientRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ModuleClientRowDto $dto */
        $dto = $this->resource;

        return $dto->toArray();
    }
}
