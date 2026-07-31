<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\TenantSettingsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TenantSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantSettingsData $settings */
        $settings = $this->resource;

        return [
            'enabled' => $settings->enabled,
        ];
    }
}
