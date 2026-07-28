<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuideVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuideVersion */
final class TaxGuideVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuideVersion $version */
        $version = $this->resource;

        return [
            'id' => $version->id,
            'tenant_id' => $version->tenant_id,
            'tax_guide_id' => $version->tax_guide_id,
            'version_number' => $version->version_number,
            'is_current' => $version->is_current,
            'emission_status' => $version->emission_status?->value
                ?? $version->emission_status,
            'replaces_version_id' => $version->replaces_version_id,
            'superseded_by_version_id' => $version
                ->superseded_by_version_id,
            'identifier_code' => $version->identifier_code,
            'amount_cents' => $version->amount_cents,
            'currency' => $version->currency,
            'due_at' => $version->due_at?->toIso8601String(),
            'valid_until' => $version->valid_until?->toIso8601String(),
            'content_sha256' => $version->content_sha256,
            'content_type' => $version->content_type,
            'byte_size' => $version->byte_size,
            'has_document' => $version->hasStoredDocument(),
            'idempotency_key' => $version->idempotency_key,
            'correlation_id' => $version->correlation_id,
            'remote_protocol' => $version->remote_protocol,
            'risk_level' => $version->risk_level?->value
                ?? $version->risk_level,
            'sent_at' => $version->sent_at?->toIso8601String(),
            'finished_at' => $version->finished_at?->toIso8601String(),
            'reconcile_after' => $version->reconcile_after?->toIso8601String(),
            'reconcile_attempts' => $version->reconcile_attempts,
            'error_code' => $version->error_code,
            'error_message' => $version->error_message,
            'created_at' => $version->created_at?->toIso8601String(),
            'updated_at' => $version->updated_at?->toIso8601String(),
        ];
    }
}
