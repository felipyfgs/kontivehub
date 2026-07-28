<?php

namespace App\Http\Resources\Communication;

use App\Models\CommunicationIdentity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationIdentitySummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationIdentity $identity */
        $identity = $this->resource;

        return [
            'id' => $identity->id,
            'address_masked' => $identity->address_masked,
        ];
    }
}
