<?php

namespace App\DTO\Outbound;

use App\Enums\SvrsNfceFailureReason;

final readonly class SvrsNfceEligibilityResult
{
    public function __construct(
        public bool $eligible,
        public ?SvrsNfceFailureReason $reason = null,
        public ?string $sanitizedDetail = null,
    ) {}

    public static function yes(): self
    {
        return new self(true);
    }

    public static function no(SvrsNfceFailureReason $reason, ?string $detail = null): self
    {
        return new self(false, $reason, $detail);
    }
}
