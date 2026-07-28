<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationIdentityLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationIdentityLinkResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationIdentityLink $link */
        $link = $this->resource;

        return [
            'id' => $link->id,
            'identity_id' => $link->identity_id,
            'client_id' => $link->client_id,
            'client_name' => $link->client?->displayLabel(),
            'client_contact_id' => $link->client_contact_id,
            'client_contact_name' => $link->clientContact?->name,
            'is_primary' => (bool) $link->is_primary,
            'receives_automatic' => (bool) $link->receives_automatic,
        ];
    }
}
