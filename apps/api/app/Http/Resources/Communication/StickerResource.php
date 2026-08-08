<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\StickerAvailability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class StickerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $content = $this->resource->relationLoaded('content') ? $this->content : null;

        return [
            'id' => $this->public_id,
            'inbox_id' => (int) $this->inbox_id,
            'source' => $this->source->value,
            'availability' => $this->availability->value,
            'available' => $this->availability === StickerAvailability::Available && $content !== null,
            'unavailable_reason' => $this->unavailable_reason,
            'device_favorite' => (bool) $this->device_favorite,
            'app_favorite' => (bool) $this->app_favorite,
            'mime_type' => $content?->mime_type,
            'size_bytes' => $content?->size_bytes,
            'width' => $content?->width,
            'height' => $content?->height,
            'animated' => $content?->animated,
            'preview_url' => $this->availability === StickerAvailability::Available && $content !== null
                ? route('communication.stickers.preview', ['sticker' => $this->public_id], false)
                : null,
            'last_observed_at' => $this->last_observed_at?->toAtomString(),
        ];
    }
}
