<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class RecipientIdentityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'masked' => $this->address_masked,
            'is_primary' => (bool) $this->getAttribute('link_is_primary'),
            'receives_automatic' => (bool) $this->getAttribute('link_receives_automatic'),
        ];
    }
}
