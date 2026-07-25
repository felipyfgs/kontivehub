<?php

namespace App\DTO\Outbound;

use App\Enums\SvrsNfceTransportOutcome;

final readonly class SvrsNfceParseResult
{
    public function __construct(
        public SvrsNfceTransportOutcome $outcome,
        public ?string $xmlBytes = null,
        public ?string $parserVersion = null,
        public ?string $sanitizedDetail = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->outcome === SvrsNfceTransportOutcome::Captured
            && is_string($this->xmlBytes)
            && $this->xmlBytes !== '';
    }
}
