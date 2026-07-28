<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuidePaymentConfirmation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuidePaymentConfirmation */
final class TaxGuidePaymentConfirmationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuidePaymentConfirmation $confirmation */
        $confirmation = $this->resource;

        return [
            'id' => $confirmation->id,
            'tenant_id' => $confirmation->tenant_id,
            'tax_guide_id' => $confirmation->tax_guide_id,
            'tax_guide_version_id' => $confirmation->tax_guide_version_id,
            'source' => $confirmation->source,
            'external_id' => $confirmation->external_id,
            'amount_cents' => $confirmation->amount_cents,
            'currency' => $confirmation->currency,
            'paid_at' => $confirmation->paid_at?->toIso8601String(),
            'content_sha256' => $confirmation->content_sha256,
            'content_type' => $confirmation->content_type,
            'byte_size' => $confirmation->byte_size,
            'created_at' => $confirmation->created_at?->toIso8601String(),
        ];
    }
}
