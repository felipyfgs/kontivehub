<?php

namespace App\Http\Resources;

use App\Models\ClientContact;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientContact */
final class ClientContactResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientContact $contact */
        $contact = $this->resource;

        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'name' => $contact->name,
            'role' => $contact->role,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_whatsapp' => $contact->is_whatsapp,
            'is_primary' => $contact->is_primary,
            'receives_alerts' => $contact->receives_alerts,
            'notes' => $contact->notes,
            'is_active' => $contact->is_active,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
