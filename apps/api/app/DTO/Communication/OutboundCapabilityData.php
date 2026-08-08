<?php

namespace App\DTO\Communication;

use App\Enums\Communication\OutboundCapabilityUnavailableReason;

/**
 * Capability contract for one outbound family.
 *
 * @phpstan-type OutboundCapabilityArray array{
 *     family: string,
 *     enabled: bool,
 *     reason: string|null,
 *     requires_permission: string,
 *     limits: array<string, int|list<string>>,
 *     variants: array<string, array{enabled: bool, reason: string|null}>
 * }
 */
final readonly class OutboundCapabilityData
{
    /**
     * @param  array<string, int|list<string>>  $limits
     * @param  array<string, OutboundCapabilityVariantData>  $variants
     * @param  array<string, bool|int|list<string>|string>  $compatFields
     */
    public function __construct(
        public string $family,
        public bool $enabled,
        public ?OutboundCapabilityUnavailableReason $reason = null,
        public string $requiresPermission = 'communication.reply',
        public array $limits = [],
        public array $variants = [],
        private array $compatFields = [],
    ) {}

    /** @return OutboundCapabilityArray&array<string, bool|int|list<string>|string> */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'enabled' => $this->enabled,
            'reason' => $this->reason?->value,
            'requires_permission' => $this->requiresPermission,
            'limits' => $this->limits,
            'variants' => array_map(
                static fn (OutboundCapabilityVariantData $variant): array => $variant->toArray(),
                $this->variants,
            ),
            // Additive compatibility for existing consumers of the loose payload.
            'supported' => $this->enabled,
            ...$this->compatFields,
        ];
    }

    public function unavailable(OutboundCapabilityUnavailableReason $reason): self
    {
        return new self(
            family: $this->family,
            enabled: false,
            reason: $reason,
            requiresPermission: $this->requiresPermission,
            limits: $this->limits,
            variants: array_map(
                static fn (): OutboundCapabilityVariantData => new OutboundCapabilityVariantData(false, $reason),
                $this->variants,
            ),
            compatFields: array_map(
                static fn (bool|int|array|string $value): bool|int|array|string => is_bool($value) ? false : $value,
                $this->compatFields,
            ),
        );
    }
}
