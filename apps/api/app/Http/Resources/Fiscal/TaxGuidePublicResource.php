<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuide */
final class TaxGuidePublicResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuide $guide */
        $guide = $this->resource;

        return $guide->toPublicArray();
    }
}
