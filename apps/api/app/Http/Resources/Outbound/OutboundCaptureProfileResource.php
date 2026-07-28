<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundCaptureProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCaptureProfileResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCaptureProfile $profile */
        $profile = $this->resource;

        return [
            'id' => $profile->id,
            'client_id' => $profile->client_id,
            'establishment_id' => $profile->establishment_id,
            'uf' => $profile->uf,
            'environment' => $profile->environment,
            'model' => $profile->model->value,
            'mode' => $profile->mode->value,
            'status' => $profile->status->value,
            'consent_recorded' => $profile->consent_recorded,
            'mandate_reference' => $profile->mandate_reference,
            'allowlisted' => $profile->allowlisted,
            'kill_switch' => $profile->kill_switch,
            'csc' => $profile->cscPublicState(),
            'activated_at' => $profile->activated_at?->toIso8601String(),
        ];
    }
}
