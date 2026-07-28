<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxAlert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MailboxAlertPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, MailboxAlert> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = MailboxAlertResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
