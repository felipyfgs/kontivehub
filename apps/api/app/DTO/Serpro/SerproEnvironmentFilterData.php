<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEnvironment;

final readonly class SerproEnvironmentFilterData
{
    public function __construct(
        public ?SerproEnvironment $environment,
    ) {}

    public function environmentOr(SerproEnvironment $fallback): SerproEnvironment
    {
        return $this->environment ?? $fallback;
    }
}
