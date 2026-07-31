<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\InboxPairingResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class InboxPairingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var InboxPairingResult $pairing */
        $pairing = $this->resource;

        return $pairing->state;
    }
}
