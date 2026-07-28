<?php

namespace App\DTO\Esocial;

use App\Models\FiscalMonitoringRun;

final readonly class FgtsEsocialQueuedSyncData
{
    /** @param array<string, mixed> $coverage */
    public function __construct(
        public int $clientId,
        public string $competencePeriodKey,
        public ?int $establishmentId,
        public ?FiscalMonitoringRun $run,
        public array $coverage,
    ) {}
}
