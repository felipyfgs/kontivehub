<?php

namespace App\DTO\Fiscal\Monitoring;

use Illuminate\Support\Collection;

final readonly class ClientFiscalRecordsData
{
    /**
     * @param  Collection<int, object>  $records
     */
    public function __construct(
        public int $clientId,
        public Collection $records,
    ) {}
}
