<?php

namespace App\Http\Resources;

use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Establishment */
final class EstablishmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Establishment $establishment */
        $establishment = $this->resource;

        return [
            'id' => $establishment->id,
            'tenant_id' => $establishment->tenant_id,
            'client_id' => $establishment->client_id,
            'cnpj' => $establishment->cnpj,
            'trade_name' => $establishment->trade_name,
            'is_headquarters' => $establishment->is_headquarters,
            'is_active' => $establishment->is_active,
            'registration_status' => $establishment->registration_status?->value
                ?? $establishment->registration_status,
            'registration_status_at' => $establishment->registration_status_at?->toDateString(),
            'registration_status_reason' => $establishment->registration_status_reason,
            'activity_started_at' => $establishment->activity_started_at?->toDateString(),
            'main_cnae_code' => $establishment->main_cnae_code,
            'main_cnae_name' => $establishment->main_cnae_name,
            'secondary_cnaes' => is_array($establishment->secondary_cnaes)
                ? $establishment->secondary_cnaes
                : [],
            'state_registrations' => is_array($establishment->state_registrations)
                ? $establishment->state_registrations
                : [],
            'shareholders' => is_array($establishment->shareholders)
                ? $establishment->shareholders
                : [],
            'address' => $establishment->addressPayload(),
            'public_email' => $establishment->public_email,
            'public_phone' => $establishment->public_phone,
            'public_phone_secondary' => $establishment->public_phone_secondary,
            'public_fax' => $establishment->public_fax,
            'special_situation' => $establishment->special_situation,
            'special_situation_at' => $establishment->special_situation_at?->toDateString(),
            'simples_optant' => $establishment->simples_optant,
            'mei_optant' => $establishment->mei_optant,
            'capture_enabled' => $establishment->capture_enabled,
            'registration_source' => $establishment->registration_source?->value
                ?? $establishment->registration_source,
            'registration_refreshed_at' => $establishment->registration_refreshed_at?->toIso8601String(),
            'created_at' => $establishment->created_at?->toIso8601String(),
            'updated_at' => $establishment->updated_at?->toIso8601String(),
        ];
    }
}
