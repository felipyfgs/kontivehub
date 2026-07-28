<?php

namespace App\Http\Resources\Fiscal;

use App\Services\Fiscal\Mutations\MutationPreflightResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MutationPreflightResult */
final class FiscalMutationPreflightResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MutationPreflightResult $result */
        $result = $this->resource;

        return $result->toArray();
    }

    public function withResponse($request, $response): void
    {
        /** @var MutationPreflightResult $result */
        $result = $this->resource;
        if (! $result->eligible) {
            $response->setStatusCode(422);
        }
    }
}
