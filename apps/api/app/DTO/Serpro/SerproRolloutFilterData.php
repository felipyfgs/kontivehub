<?php

namespace App\DTO\Serpro;

final readonly class SerproRolloutFilterData
{
    public function __construct(
        public ?string $status,
    ) {}
}
