<?php

namespace App\DTO\Serpro;

final readonly class SerproBudgetFilterData
{
    public function __construct(
        public ?string $scope,
    ) {}
}
