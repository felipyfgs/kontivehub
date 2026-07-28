<?php

namespace App\DTO\Platform;

use App\Enums\ActivationMethod;

final readonly class ActivationMethodData
{
    public function __construct(
        public ActivationMethod $method,
    ) {}
}
