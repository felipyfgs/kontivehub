<?php

namespace App\Http\Resources;

use App\DTO\Serpro\EligibilityResult;
use App\Enums\SerproEligibilityCode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EligibilityResult */
final class TenantSerproEligibilityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EligibilityResult $result */
        $result = $this->resource;

        return [
            'eligible' => $result->eligible,
            'codes' => array_map(
                fn (SerproEligibilityCode $code): string => $code->value,
                $result->codes,
            ),
            'primary_code' => $result->primaryCode()->value,
            'context' => $result->context,
        ];
    }
}
