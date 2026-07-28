<?php

namespace App\Http\Resources\Communication;

use App\Services\Communication\Flows\CommunicationFlowGraphValidationResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationFlowGraphValidationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationFlowGraphValidationResult $result */
        $result = $this->resource;

        return [
            'valid' => true,
            'graph_digest' => $result->digest,
        ];
    }
}
