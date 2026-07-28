<?php

namespace App\Http\Resources;

use App\DTO\Clients\ClientDetailData;
use App\Models\Establishment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientDetailData */
final class ClientDetailResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientDetailData $detail */
        $detail = $this->resource;
        $client = $detail->client;
        $payload = ClientResource::make($client)->resolve($request);
        $primary = $client->establishments->firstWhere('is_headquarters', true)
            ?? $client->establishments->first();

        $payload['procuracao_status'] = $detail->procuracaoProjection['status'];
        $payload['procuracao_valid_to'] = $detail->procuracaoProjection['valid_to'];
        $payload['procuracao_checked_at'] = $detail->procuracaoProjection['checked_at'];
        $payload['cnpj'] = $primary?->cnpj;
        $payload['trade_name'] = $primary?->trade_name;
        $payload['establishments'] = $client->establishments
            ->map(function (Establishment $establishment) use ($detail, $request): array {
                $data = EstablishmentResource::make($establishment)->resolve($request);
                $data['capture_eligibility'] = $detail->captureEligibility[$establishment->id] ?? null;

                return $data;
            })
            ->values()
            ->all();
        $payload['contacts'] = ClientContactResource::collection(
            $client->contacts,
        )->resolve($request);
        $payload['custom_fields'] = ClientCustomFieldResource::collection(
            $client->customFields,
        )->resolve($request);

        $credential = $client->credential;
        $payload['credential_summary'] = $credential === null
            ? null
            : [
                'status' => $credential->status?->value ?? $credential->status,
                'valid_to' => $credential->valid_to?->toIso8601String(),
                'expires_alert_30' => (bool) $credential->expires_alert_30,
                'expires_alert_7' => (bool) $credential->expires_alert_7,
                'expires_alert_1' => (bool) $credential->expires_alert_1,
            ];

        return $payload;
    }
}
