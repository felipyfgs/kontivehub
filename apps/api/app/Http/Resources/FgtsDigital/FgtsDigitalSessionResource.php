<?php

namespace App\Http\Resources\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalSessionData;
use App\Models\FgtsDigitalSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalSession $session */
        $session = $this->resource;

        return FgtsDigitalSessionData::fromModel($session)->toArray();
    }
}
