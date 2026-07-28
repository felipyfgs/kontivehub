<?php

namespace App\Http\Resources;

use App\Models\WorkExport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkExport $export */
        $export = $this->resource;

        return [
            'id' => $export->id,
            'status' => $export->status->value,
            'filters_snapshot' => $export->filters_snapshot,
            'byte_size' => $export->byte_size,
            'row_count' => $export->row_count,
            'error_message' => $export->error_message,
            'expires_at' => $export->expires_at?->toIso8601String(),
            'completed_at' => $export->completed_at?->toIso8601String(),
        ];
    }
}
