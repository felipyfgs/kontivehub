<?php

namespace App\Http\Resources;

use App\Models\TaxProxyPower;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxProxyPower */
final class TaxProxyPowerResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxProxyPower $power */
        $power = $this->resource;

        return [
            'id' => $power->id,
            'tenant_id' => $power->tenant_id,
            'client_id' => $power->client_id,
            'author_identity_masked' => $this->mask($power->author_identity),
            'contributor_cnpj_masked' => $this->mask($power->contributor_cnpj),
            'system_code' => $power->system_code,
            'service_code' => $power->service_code,
            'power_code' => $power->power_code,
            'environment' => $power->environment,
            'source' => $power->source->value,
            'provenance' => $power->provenance,
            'status' => $power->status->value,
            'valid_from' => $power->valid_from?->toIso8601String(),
            'valid_to' => $power->valid_to?->toIso8601String(),
            'accepted_at' => $power->accepted_at?->toIso8601String(),
            'freshness_checked_at' => $power->freshness_checked_at?->toIso8601String(),
            'closed_at' => $power->closed_at?->toIso8601String(),
            'segregation_class' => $power->segregation_class,
            'evidence_ref' => $power->evidence_ref,
            'evidence_sha256' => $power->evidence_sha256,
            'verified_at' => $power->verified_at?->toIso8601String(),
            'last_check_result' => $power->last_check_result,
            'is_currently_valid' => $power->isCurrentlyValid(),
            'is_accepted' => $power->isAcceptedByAuthorizee(),
            'is_fresh' => $power->isFresh(),
            'created_at' => $power->created_at?->toIso8601String(),
            'updated_at' => $power->updated_at?->toIso8601String(),
        ];
    }

    private function mask(string $value): string
    {
        $normalized = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $value) ?? $value);
        $length = strlen($normalized);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($normalized, 0, 2)
            .str_repeat('*', max(0, $length - 6))
            .substr($normalized, -4);
    }
}
