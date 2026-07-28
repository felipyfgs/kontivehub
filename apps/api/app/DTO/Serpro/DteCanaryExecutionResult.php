<?php

namespace App\DTO\Serpro;

use App\Models\SerproDteCanaryRequest;

final readonly class DteCanaryExecutionResult
{
    public function __construct(
        public SerproDteCanaryRequest $request,
        public bool $replay,
    ) {}
}
