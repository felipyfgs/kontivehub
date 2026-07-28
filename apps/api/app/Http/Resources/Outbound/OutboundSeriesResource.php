<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundSeriesCursor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundSeriesResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundSeriesCursor $series */
        $series = $this->resource;

        return [
            'id' => $series->id,
            'profile_id' => $series->outbound_capture_profile_id,
            'establishment_id' => $series->establishment_id,
            'environment' => $series->environment,
            'model' => $series->model->value,
            'series' => $series->series,
            'seed_nnf' => $series->seed_nnf,
            'discovery_position' => $series->discovery_position,
            'position_kind' => 'nNF',
            'highest_confirmed_nnf' => $series->highest_confirmed_nnf,
            'status' => $series->status->value,
            'tp_emis' => $series->tp_emis,
            'seed_access_key' => $series->seed_access_key,
            'seed_issued_at' => $series->seed_issued_at?->toIso8601String(),
            'next_run_at' => $series->next_run_at?->toIso8601String(),
            'last_run_at' => $series->last_run_at?->toIso8601String(),
            'series_closed_for_mutation' => $series->series_closed_for_mutation,
        ];
    }
}
