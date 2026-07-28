<?php

namespace App\Http\Resources\FgtsEsocial;

use App\DTO\Esocial\FgtsEsocialQueuedSyncData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialQueuedSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsEsocialQueuedSyncData $result */
        $result = $this->resource;

        return [
            'queued' => true,
            'client_id' => $result->clientId,
            'competence_period_key' => $result->competencePeriodKey,
            'establishment_id' => $result->establishmentId,
            'run' => $result->run === null
                ? null
                : (new FiscalMonitoringRunResource($result->run))->resolve($request),
            'coverage' => $result->coverage,
        ];
    }
}
