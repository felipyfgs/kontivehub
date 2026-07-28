<?php

namespace App\DTO\FgtsDigital;

final readonly class FgtsDigitalSessionImportData
{
    /** @param array<string, mixed> $storageState */
    public function __construct(
        public int $clientId,
        public array $storageState,
    ) {}
}
