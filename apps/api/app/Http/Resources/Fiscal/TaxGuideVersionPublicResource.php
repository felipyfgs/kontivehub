<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuideVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuideVersion */
final class TaxGuideVersionPublicResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuideVersion $version */
        $version = $this->resource;

        return $version->toPublicArray();
    }
}
