<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundCaptureRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCaptureRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCaptureRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'profile_id' => $run->outbound_capture_profile_id,
            'series_cursor_id' => $run->outbound_series_cursor_id,
            'run_type' => $run->run_type,
            'status' => $run->status->value,
            'position_kind' => 'nNF',
            'nnf_start' => $run->nnf_start,
            'nnf_end' => $run->nnf_end,
            'numbers_consulted' => $run->numbers_consulted,
            'keys_discovered' => $run->keys_discovered,
            'xml_persisted' => $run->xml_persisted,
            'gaps_open' => $run->gaps_open,
            'attempts_total' => $run->attempts_total,
            'result_summary' => $run->result_summary,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'triggered_by' => $run->triggered_by,
        ];
    }
}
