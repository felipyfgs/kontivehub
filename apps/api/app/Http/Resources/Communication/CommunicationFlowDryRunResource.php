<?php

namespace App\Http\Resources\Communication;

use App\Services\Communication\Flows\CommunicationFlowDryRunResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationFlowDryRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowDryRunResult $result */
        $result = $this->resource;

        return $result->toArray();
    }
}
