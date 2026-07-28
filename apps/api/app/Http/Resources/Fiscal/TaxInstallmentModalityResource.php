<?php

namespace App\Http\Resources\Fiscal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
final class TaxInstallmentModalityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $modality */
        $modality = $this->resource;

        return [
            'code' => $modality['code'],
            'label' => $modality['label'],
            'regime' => $modality['regime'],
            'official_state' => $modality['official_state'],
            'official_state_label' => $modality['official_state_label'],
            'monitoring_supported' => $modality['monitoring_supported'],
            'executable' => $modality['executable'],
            'required_power' => $modality['required_power'],
        ];
    }
}
