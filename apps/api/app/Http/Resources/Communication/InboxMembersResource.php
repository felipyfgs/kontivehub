<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\InboxMembersData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InboxMembersResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InboxMembersData $members */
        $members = $this->resource;

        return [
            'membership_ids' => $members->membershipIds,
        ];
    }
}
