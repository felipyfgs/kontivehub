<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\RecipientConfigurationData;
use App\Enums\Communication\RecipientMode;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RecipientConfigurationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var RecipientConfigurationData $configuration */
        $configuration = $this->resource;
        $preference = $configuration->preference;

        return [
            'client_id' => (int) $configuration->client->id,
            'preference_id' => $preference?->id,
            'recipient_mode' => ($preference?->recipient_mode instanceof RecipientMode
                ? $preference->recipient_mode
                : RecipientMode::Primary)->value,
            'lock_version' => (int) ($preference?->lock_version ?? 0),
            'selected_identity_ids' => $configuration->selectedIdentityIds,
            'identities' => RecipientIdentityResource::collection(
                $configuration->identities,
            ),
        ];
    }
}
