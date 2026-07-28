<?php

namespace App\Http\Resources;

use App\Enums\SerproDteControlMode;
use App\Models\SerproDteControl;
use App\Support\Serpro\DteCanaryCoordinates;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproDteControl */
final class SerproDteControlResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproDteControl $control */
        $control = $this->resource;

        return [
            'id' => $control->id,
            'operation_key' => $control->operation_key ?? DteCanaryCoordinates::OPERATION_KEY,
            'mode' => $control->mode instanceof SerproDteControlMode
                ? $control->mode->value
                : (string) $control->mode,
            'pilot_tenant_id' => $control->pilot_tenant_id,
            'pilot_client_id' => $control->pilot_client_id,
            'limited_max_quantity' => $control->limited_max_quantity !== null
                ? (int) $control->limited_max_quantity
                : null,
            'limited_used_quantity' => (int) $control->limited_used_quantity,
            'remaining_quantity' => $control->remainingLimitedQuantity(),
            'usage_ratio' => $control->usageRatio(),
            'cycle_code' => $control->cycle_code,
            'promoted_at' => $control->promoted_at?->toIso8601String(),
            'disabled_at' => $control->disabled_at?->toIso8601String(),
            'disable_reason' => $control->disable_reason,
            'alert_percent' => (int) ($control->alert_percent ?? DteCanaryCoordinates::ALERT_PERCENT),
            'alert_80_emitted' => (bool) $control->alert_80_emitted,
            'alert_100_emitted' => (bool) $control->alert_100_emitted,
            'updated_at' => $control->updated_at?->toIso8601String(),
        ];
    }
}
