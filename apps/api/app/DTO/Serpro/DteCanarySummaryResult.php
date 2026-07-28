<?php

namespace App\DTO\Serpro;

use App\Models\SerproDteCanaryRequest;
use App\Models\SerproDteControl;

final readonly class DteCanarySummaryResult
{
    /**
     * @param  array<string, string>  $coordinates
     * @param  array{allowed: bool, blockers: list<string>, checks: array<string, bool>}|null  $gate
     */
    public function __construct(
        public SerproDteControl $control,
        public array $coordinates,
        public ?SerproDteCanaryRequest $request,
        public ?array $gate,
    ) {}
}
