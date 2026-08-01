<?php

namespace App\Http\Resources\Work;

use App\Domain\Work\WorkRoutineRecurrenceSchedule;
use App\Enums\Work\RecurrencePeriodOffset;
use App\Models\WorkProcessTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcessTemplateRecurrenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessTemplate $template */
        $template = $this->resource;

        return [
            'recurrence_enabled' => (bool) $template->recurrence_enabled,
            'recurrence_frequency' => $template->recurrence_frequency?->value,
            'generation_day' => (int) (
                $template->generation_day
                ?? WorkRoutineRecurrenceSchedule::MIN_GENERATION_DAY
            ),
            'anchor_month' => $template->anchor_month,
            'period_offset' => (
                $template->period_offset
                ?? RecurrencePeriodOffset::Previous
            )->value,
            'next_run_at' => $template->next_run_at?->toIso8601String(),
            'recurrence_owner_membership_id' => $template->recurrence_owner_membership_id,
            'lock_version' => $template->lock_version,
        ];
    }
}
