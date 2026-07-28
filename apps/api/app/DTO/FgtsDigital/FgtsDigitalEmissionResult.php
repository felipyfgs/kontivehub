<?php

namespace App\DTO\FgtsDigital;

use App\Models\FgtsDigitalRun;

final readonly class FgtsDigitalEmissionResult
{
    public function __construct(
        public FgtsDigitalRun $run,
        public bool $reused,
    ) {}
}
