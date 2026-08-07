<?php

namespace App\DTO\Communication;

use App\Enums\Communication\OutboundCapabilityUnavailableReason;

/**
 * A documented, discriminated outbound capability variant.
 *
 * @phpstan-type OutboundCapabilityVariantArray array{enabled: bool, reason: string|null}
 */
final readonly class OutboundCapabilityVariantData
{
    public function __construct(
        public bool $enabled,
        public ?OutboundCapabilityUnavailableReason $reason = null,
    ) {}

    /** @return OutboundCapabilityVariantArray */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'reason' => $this->reason?->value,
        ];
    }
}
