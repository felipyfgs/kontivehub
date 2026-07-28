<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Mutations\TaxGuideReconcileResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuideReconcileResultData */
final class TaxGuideReconcileResultResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuideReconcileResultData $result */
        $result = $this->resource;

        return [
            'guide' => (new TaxGuidePublicResource($result->guide))->resolve($request),
            'version' => (new TaxGuideVersionPublicResource($result->version))->resolve($request),
            'outcome' => $result->outcome,
        ];
    }
}
