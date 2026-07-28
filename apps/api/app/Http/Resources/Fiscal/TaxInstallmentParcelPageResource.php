<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxInstallmentParcel;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class TaxInstallmentParcelPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, TaxInstallmentParcel> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = TaxInstallmentParcelResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
