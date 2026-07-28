<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\TaxGuidePageData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuidePageData */
final class TaxGuidePageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuidePageData $data */
        $data = $this->resource;
        $payload = $data->page->toArray();
        $payload['payment_counters'] = $data->paymentCounters;

        return $payload;
    }
}
