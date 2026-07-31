<?php

namespace App\Http\Resources\Communication;

use App\Services\Communication\Flows\FlowDryRunResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FlowDryRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FlowDryRunResult $result */
        $result = $this->resource;

        return $result->toArray();
    }
}
