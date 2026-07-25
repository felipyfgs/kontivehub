<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_provisional' => (bool) $this->is_provisional,
            'is_active' => (bool) $this->is_active,
            'identities' => $this->whenLoaded('identities', fn () => $this->identities->map(fn ($identity) => [
                'id' => $identity->id,
                'channel' => $identity->channel?->value ?? $identity->channel,
                'address_masked' => $identity->address_masked,
                'is_active' => (bool) $identity->is_active,
                'links' => $identity->relationLoaded('clientLinks')
                    ? $identity->clientLinks->map(fn ($link) => [
                        'id' => $link->id,
                        'client_id' => $link->client_id,
                        'client_name' => $link->relationLoaded('client')
                            ? $link->client?->displayLabel()
                            : null,
                        'client_contact_id' => $link->client_contact_id,
                        'client_contact_name' => $link->relationLoaded('clientContact')
                            ? $link->clientContact?->name
                            : null,
                        'is_primary' => (bool) $link->is_primary,
                        'receives_automatic' => (bool) $link->receives_automatic,
                    ])->values()
                    : [],
            ])->values()),
            'purged_at' => $this->purged_at?->toIso8601String(),
        ];
    }
}
