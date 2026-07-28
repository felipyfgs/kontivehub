<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationInboxMembersData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationInboxMembersResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationInboxMembersData $members */
        $members = $this->resource;

        return [
            'membership_ids' => $members->membershipIds,
        ];
    }
}
