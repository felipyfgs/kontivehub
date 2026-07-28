<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class ProjectDeclarationData
{
    public function __construct(
        public int $clientId,
        public string $periodKey,
        public ?string $obligationCode,
        public ?int $periodYear,
        public ?int $periodMonth,
        public bool $all,
    ) {}
}
