<?php

namespace App\DTO\Esocial;

final readonly class FgtsEsocialSyncData
{
    public function __construct(
        public int $clientId,
        public string $competencePeriodKey,
        public ?int $establishmentId,
        public bool $dispatchJob = true,
        public bool $createRun = true,
        public ?string $correlationId = null,
    ) {}
}
