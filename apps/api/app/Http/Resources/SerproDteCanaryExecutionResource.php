<?php

namespace App\Http\Resources;

use App\DTO\Serpro\DteCanaryExecutionResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DteCanaryExecutionResult */
final class SerproDteCanaryExecutionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var DteCanaryExecutionResult $result */
        $result = $this->resource;

        return SerproDteCanaryRequestResource::make($result->request)->resolve($request);
    }

    /** @return array{replay: bool} */
    public function with(Request $request): array
    {
        /** @var DteCanaryExecutionResult $result */
        $result = $this->resource;

        return ['replay' => $result->replay];
    }
}
