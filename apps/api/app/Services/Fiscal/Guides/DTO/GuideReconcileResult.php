<?php

namespace App\Services\Fiscal\Guides\DTO;

/**
 * outcome: FOUND | NOT_FOUND | STILL_UNKNOWN | REJECTED
 */
final class GuideReconcileResult
{
    public function __construct(
        public readonly string $outcome,
        public readonly ?GuideEmissionResult $emission = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}
}
