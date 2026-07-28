<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationTenantSettingsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationTenantSettingsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationTenantSettingsData $settings */
        $settings = $this->resource;

        return [
            'enabled' => $settings->enabled,
        ];
    }
}
