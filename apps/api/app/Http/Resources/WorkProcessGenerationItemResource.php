<?php

namespace App\Http\Resources;

use App\Models\WorkProcessGenerationItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkProcessGenerationItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessGenerationItem $item */
        $item = $this->resource;

        return [
            'id' => $item->id,
            'client_id' => $item->client_id,
            'status' => $item->status->value,
            'is_blocked' => $item->is_blocked,
            'preview_payload' => $item->preview_payload,
            'alerts' => $item->alerts,
            'conflicts' => $item->conflicts,
            'created_process_id' => $item->created_process_id,
            'error_message' => $item->error_message,
        ];
    }
}
