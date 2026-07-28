<?php

namespace App\DTO\Fiscal\Mutations;

use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;

final readonly class TaxGuideIssuanceResultData
{
    public function __construct(
        public TaxGuide $guide,
        public TaxGuideVersion $version,
        public bool $reused,
        public bool $substituted,
    ) {}

    public function httpStatus(): int
    {
        $status = $this->version->emission_status?->value;

        return match ($status) {
            'UNKNOWN_RESULT' => 202,
            default => $this->reused ? 200 : 201,
        };
    }
}
