<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantAutXmlCursorOverviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantAutXmlCursorOverviewData */
final class TenantAutXmlCursorOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'cursors' => TenantAutXmlCursorResource::collection($this->cursors)
                ->resolve($request),
            'stream' => TenantAutXmlStreamResource::make($this->stream)
                ->resolve($request),
            'recent_runs' => TenantDistributionRunResource::collection(
                $this->recentRuns,
            )->resolve($request),
        ];
    }
}
