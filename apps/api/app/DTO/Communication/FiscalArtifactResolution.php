<?php

namespace App\DTO\Communication;

final readonly class FiscalArtifactResolution
{
    public function __construct(
        public ?ResolvedFiscalArtifact $artifact,
        public ?string $reason = null,
    ) {}

    public static function found(ResolvedFiscalArtifact $artifact): self
    {
        return new self($artifact);
    }

    public static function missing(string $reason = 'DOCUMENT_NOT_FOUND'): self
    {
        return new self(null, $reason);
    }
}
