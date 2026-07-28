<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SitfisReadData;
use App\Models\Client;

final class ShowSitfisSituationRequest extends SitfisReadRequest
{
    private bool $clientResolved = false;

    private ?Client $resolvedClient = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function readData(): SitfisReadData
    {
        return new SitfisReadData(
            clientId: (int) $this->validated('client_id'),
        );
    }

    public function client(): ?Client
    {
        if (! $this->clientResolved) {
            $this->clientResolved = true;
            $this->resolvedClient = $this->findCurrentTenantClient(
                $this->readData()->clientId,
            );
        }

        return $this->resolvedClient;
    }
}
