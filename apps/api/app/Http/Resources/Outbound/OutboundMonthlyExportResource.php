<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundMonthlyExportResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundMonthlyExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundMonthlyExportResult $result */
        $result = $this->resource;

        return [
            'export' => [
                'id' => $result->export->id,
                'status' => $result->export->status,
                'filters' => $result->export->filters,
                'include_events' => $result->export->include_events,
                'created_at' => $result->export->created_at?->toIso8601String(),
            ],
            'readiness' => (new OutboundMonthlyReadinessResource(
                $result->readiness,
            ))->resolve($request),
            'has_manifest' => $result->hasManifest,
            'completeness_scope' => 'known_documents_only',
        ];
    }
}
