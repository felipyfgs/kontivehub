<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CannedResponseRenderResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CannedResponseRenderResult */
final class CannedResponseRenderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'canned_response_id' => $this->cannedResponseId,
            'conversation_id' => $this->conversationId,
            'body' => $this->body,
        ];
    }
}
