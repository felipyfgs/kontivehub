<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class WorkTimelineResource extends JsonResource
{
    /** @return list<array<string, mixed>> */
    public function toArray(Request $request): array
    {
        /** @var list<array<string, mixed>> $timeline */
        $timeline = $this->resource;

        return $timeline;
    }
}
