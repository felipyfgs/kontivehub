<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxDeadlineCalendarVersion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxDeadlineCalendarVersion */
final class TaxDeadlineCalendarVersionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxDeadlineCalendarVersion $calendar */
        $calendar = $this->resource;

        return [
            'id' => $calendar->id,
            'code' => $calendar->code,
            'version' => $calendar->version,
            'label' => $calendar->label,
            'timezone' => $calendar->timezone,
            'effective_from' => $calendar->effective_from?->toIso8601String(),
            'effective_to' => $calendar->effective_to?->toIso8601String(),
            'is_current' => $calendar->is_current,
            'source_ref' => $calendar->source_ref,
            'notes' => $calendar->notes,
        ];
    }
}
