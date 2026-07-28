<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundNumberState;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundNumberStateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundNumberState $number */
        $number = $this->resource;

        return [
            'id' => $number->id,
            'series' => $number->series,
            'nnf' => $number->nnf,
            'status' => $number->status->value,
            'candidate_access_key' => $number->candidate_access_key,
            'discovered_access_key' => $number->discovered_access_key,
            'last_cstat' => $number->last_cstat,
            'attempts' => $number->attempts,
            'next_attempt_at' => $number->next_attempt_at?->toIso8601String(),
            'key_discovered_at' => $number->key_discovered_at?->toIso8601String(),
            'xml_captured_at' => $number->xml_captured_at?->toIso8601String(),
            'has_full_xml' => $number->dfe_document_id !== null
                && $number->xml_captured_at !== null,
        ];
    }
}
