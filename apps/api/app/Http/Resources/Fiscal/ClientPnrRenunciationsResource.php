<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\ClientFiscalRecordsData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientFiscalRecordsData */
final class ClientPnrRenunciationsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientFiscalRecordsData $data */
        $data = $this->resource;

        return [
            'client_id' => $data->clientId,
            'renunciations' => FiscalPnrRenunciationResource::collection(
                $data->records,
            )->resolve($request),
        ];
    }
}
