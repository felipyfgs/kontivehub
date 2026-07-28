<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class RefreshSitfisSituationData
{
    public function __construct(
        public int $clientId,
        public bool $force,
    ) {}
}
