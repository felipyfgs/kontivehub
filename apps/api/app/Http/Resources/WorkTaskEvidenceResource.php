<?php

namespace App\Http\Resources;

use App\Models\WorkTaskEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin WorkTaskEvidence */
final class WorkTaskEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkTaskEvidence $evidence */
        $evidence = $this->resource;

        return [
            'id' => $evidence->id,
            'original_filename' => $evidence->original_filename,
            'mime_type' => $evidence->mime_type,
            'byte_size' => $evidence->byte_size,
            'sha256' => $evidence->sha256,
            'created_at' => $evidence->created_at?->toIso8601String(),
        ];
    }
}
