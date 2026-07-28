<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationInboxPairingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationInboxPairingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationInboxPairingResult $pairing */
        $pairing = $this->resource;

        return $pairing->state;
    }
}
