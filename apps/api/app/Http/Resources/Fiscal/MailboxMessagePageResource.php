<?php

namespace App\Http\Resources\Fiscal;

use App\Models\MailboxMessage;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MailboxMessagePageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, MailboxMessage> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = MailboxMessageResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
