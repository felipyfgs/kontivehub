<?php

namespace App\Http\Resources\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalEmissionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalEmissionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalEmissionResult $result */
        $result = $this->resource;

        return [
            'run' => (new FgtsDigitalRunResource($result->run))
                ->resolve($request),
            'reused' => $result->reused,
        ];
    }
}
