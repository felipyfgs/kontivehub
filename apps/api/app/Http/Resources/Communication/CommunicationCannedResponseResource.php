<?php

namespace App\Http\Resources\Communication;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationCannedResponseResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'title' => $this->title,
            'shortcut' => $this->shortcut,
            'body' => (string) $this->body_encrypted,
            'is_active' => (bool) $this->is_active,
            'lock_version' => (int) $this->lock_version,
        ];
    }
}
