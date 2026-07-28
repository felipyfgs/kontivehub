<?php

namespace App\Http\Resources\Fiscal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
final class FiscalMonitoringDataResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{data: array<string, mixed>} */
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->resource;

        return ['data' => $data];
    }
}
