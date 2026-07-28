<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\SimplesMeiSnapshotPageData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SimplesMeiSnapshotPageData */
final class FiscalSnapshotPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SimplesMeiSnapshotPageData $data */
        $data = $this->resource;
        $page = $data->page;
        $payload = $page->toArray();
        $payload['data'] = FiscalSnapshotResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
