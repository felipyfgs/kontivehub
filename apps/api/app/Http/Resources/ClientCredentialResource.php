<?php

namespace App\Http\Resources;

use App\Models\ClientCredential;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientCredential */
final class ClientCredentialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientCredential $credential */
        $credential = $this->resource;

        return [
            'id' => $credential->id,
            'client_id' => $credential->client_id,
            'status' => $credential->status->value,
            'subject_name' => $credential->subject_name,
            'holder_cnpj' => $credential->holder_cnpj,
            'fingerprint_sha256' => $credential->fingerprint_sha256,
            'valid_from' => $credential->valid_from?->toIso8601String(),
            'valid_to' => $credential->valid_to?->toIso8601String(),
            'activated_at' => $credential->activated_at?->toIso8601String(),
            'expires_alert_30' => $credential->expires_alert_30,
            'expires_alert_7' => $credential->expires_alert_7,
            'expires_alert_1' => $credential->expires_alert_1,
        ];
    }
}
