<?php

namespace App\Services\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalReadinessData;
use App\DTO\FgtsDigital\FgtsDigitalRunFilters;
use App\Models\Client;
use App\Models\FgtsDigitalRun;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class FgtsDigitalQuery
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private FgtsDigitalReadinessService $readiness,
    ) {}

    /** @return array<string, mixed> */
    public function coverage(): array
    {
        return $this->readiness->coverage();
    }

    public function readiness(int $clientId): FgtsDigitalReadinessData
    {
        return $this->readiness->check(
            $this->currentTenant->tenant(),
            $this->client($clientId),
        );
    }

    /** @return LengthAwarePaginator<int, FgtsDigitalRun> */
    public function runs(FgtsDigitalRunFilters $filters): LengthAwarePaginator
    {
        $query = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->id())
            ->orderByDesc('id');
        if ($filters->clientId !== null) {
            $query->where('client_id', $filters->clientId);
        }

        return $query->paginate($filters->perPage);
    }

    public function client(int $clientId): Client
    {
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->id())
            ->whereKey($clientId)
            ->first();

        if ($client === null) {
            throw new FgtsDigitalException(
                'Cliente não encontrado.',
                'FGTS_DIGITAL_CLIENT_NOT_FOUND',
                404,
            );
        }

        return $client;
    }

    public function previewRun(int $runId): FgtsDigitalRun
    {
        $run = FgtsDigitalRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->currentTenant->id())
            ->whereKey($runId)
            ->first();

        if ($run === null) {
            throw new FgtsDigitalException(
                'Prévia não encontrada.',
                'FGTS_DIGITAL_PREVIEW_NOT_FOUND',
                404,
            );
        }

        return $run;
    }
}
