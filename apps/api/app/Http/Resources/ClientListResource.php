<?php

namespace App\Http\Resources;

use App\DTO\Clients\ClientListItemData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientListItemData */
final class ClientListResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientListItemData $item */
        $item = $this->resource;
        $client = $item->client;
        $payload = ClientResource::make($client)->resolve($request);
        $primary = $client->establishments->first();
        $credential = $client->credential;

        $payload['procuracao_status'] = $item->procuracaoProjection['status'];
        $payload['procuracao_valid_to'] = $item->procuracaoProjection['valid_to'];
        $payload['procuracao_checked_at'] = $item->procuracaoProjection['checked_at'];
        $payload['cnpj'] = $primary?->cnpj;
        $payload['trade_name'] = $primary?->trade_name;
        $payload['credential_summary'] = $credential === null
            ? null
            : [
                'status' => $credential->status?->value ?? $credential->status,
                'valid_to' => $credential->valid_to?->toIso8601String(),
                'expires_alert_30' => (bool) $credential->expires_alert_30,
                'expires_alert_7' => (bool) $credential->expires_alert_7,
                'expires_alert_1' => (bool) $credential->expires_alert_1,
            ];
        $payload['capture_summary'] = $item->captureSummary;
        $payload['sync_summary'] = $item->syncSummary;

        return $payload;
    }
}
