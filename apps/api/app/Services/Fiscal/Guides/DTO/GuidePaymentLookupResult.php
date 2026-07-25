<?php

namespace App\Services\Fiscal\Guides\DTO;

/**
 * status: NOT_FOUND | NOT_PAID | PAID | PARTIAL
 */
final class GuidePaymentLookupResult
{
    public function __construct(
        public readonly string $status,
        public readonly ?string $externalId = null,
        public readonly ?int $amountCents = null,
        public readonly ?string $paidAtIso = null,
        public readonly ?string $source = null,
        public readonly ?string $evidenceBytes = null,
        public readonly ?string $contentType = null,
        public readonly bool $official = true,
    ) {}
}
