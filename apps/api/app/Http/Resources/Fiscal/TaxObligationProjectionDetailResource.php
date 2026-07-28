<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxObligationProjection;
use Illuminate\Http\Request;

/** @mixin TaxObligationProjection */
final class TaxObligationProjectionDetailResource extends TaxObligationProjectionResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxObligationProjection $projection */
        $projection = $this->resource;

        return parent::toArray($request) + [
            'evidences' => $projection->relationLoaded('evidences')
                ? TaxDeliveryEvidenceResource::collection(
                    $projection->evidences,
                )->resolve($request)
                : [],
            'due_rule_snapshot' => $projection->due_rule_snapshot,
            'due_history' => $projection->due_history,
        ];
    }
}
