<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SitfisReadData;
use App\Models\Client;

final class ViewSitfisHistoryRequest extends SitfisReadRequest
{
    private ?Client $resolvedClient = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function readData(): SitfisReadData
    {
        return new SitfisReadData(
            clientId: (int) $this->route('client'),
        );
    }

    public function client(): ?Client
    {
        return $this->resolvedClient ??= $this->findCurrentTenantClient(
            $this->readData()->clientId,
        );
    }
}
