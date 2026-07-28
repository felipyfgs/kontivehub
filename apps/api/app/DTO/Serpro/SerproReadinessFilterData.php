<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;

final readonly class SerproReadinessFilterData
{
    public function __construct(
        public ?SerproEnvironment $environment,
        public bool $persist,
    ) {}
}
