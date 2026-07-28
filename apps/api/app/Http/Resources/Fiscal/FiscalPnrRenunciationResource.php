<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalPnrRenunciation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalPnrRenunciation */
final class FiscalPnrRenunciationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalPnrRenunciation $renunciation */
        $renunciation = $this->resource;

        return [
            'id' => $renunciation->id,
            'client_id' => $renunciation->client_id,
            'renunciation_id' => $renunciation->renunciation_id,
            'status' => $renunciation->status,
            'source_provenance' => $renunciation->source_provenance?->value,
            'summary' => $renunciation->summary_sanitized,
            'occurred_at' => $renunciation->occurred_at?->toIso8601String(),
            'observed_at' => $renunciation->observed_at?->toIso8601String(),
            'refreshed_at' => $renunciation->refreshed_at?->toIso8601String(),
            'receipt' => $renunciation->hasReceiptDescriptor() ? [
                'mime_type' => $renunciation->receipt_mime_type,
                'byte_size' => $renunciation->receipt_byte_size,
                'observed_at' => $renunciation->receipt_observed_at
                    ?->toIso8601String(),
            ] : null,
        ];
    }
}
