<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalRegistrationLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalRegistrationLink */
final class FiscalRegistrationLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalRegistrationLink $link */
        $link = $this->resource;

        return [
            'id' => $link->id,
            'client_id' => $link->client_id,
            'contributor_ref' => substr(
                hash('sha256', (string) $link->contributor_cnpj),
                0,
                12,
            ),
            'link_key' => $link->link_key,
            'status' => $link->status,
            'evidence_version' => $link->evidence_version,
            'operation_key' => $link->operation_key,
            'source_provenance' => $link->source_provenance,
            'is_simulated' => $link->is_simulated,
            'summary' => $link->summary_sanitized,
            'observed_at' => $link->observed_at?->toIso8601String(),
            'refreshed_at' => $link->refreshed_at?->toIso8601String(),
        ];
    }
}
