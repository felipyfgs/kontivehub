<?php

namespace App\DTO\Fiscal\Mutations;

final readonly class TaxGuideDownloadTokenData
{
    public function __construct(
        public string $token,
        public string $expiresAt,
        public int $versionId,
    ) {}
}
