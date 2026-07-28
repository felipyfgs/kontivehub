<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxDetailPreviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxDetailPreviewData */
final class MailboxDetailPreviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxDetailPreviewData $data */
        $data = $this->resource;

        return [
            'has_body' => $data->hasBody,
            'cost' => $data->cost,
        ];
    }
}
