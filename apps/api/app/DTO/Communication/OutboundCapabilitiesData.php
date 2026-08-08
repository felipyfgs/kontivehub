<?php

namespace App\DTO\Communication;

use InvalidArgumentException;

/**
 * Public outbound-capabilities document.
 *
 * @phpstan-type OutboundCapabilitiesArray array{
 *     enabled: bool,
 *     requires_permission: string,
 *     kinds: array<string, array<string, mixed>>,
 *     max_media_bytes: int,
 *     conversation_initiation: array{enabled: bool, reason: string|null, requires_permission: string}
 * }
 */
final readonly class OutboundCapabilitiesData
{
    /** @param array<string, OutboundCapabilityData> $kinds */
    public function __construct(
        public bool $enabled,
        public string $requiresPermission,
        public array $kinds,
        public int $maxMediaBytes,
        /** @var array{enabled:bool,reason:string|null,requires_permission:string} */
        public array $conversationInitiation,
    ) {
        foreach ($this->kinds as $family => $capability) {
            if ($capability->family !== $family) {
                throw new InvalidArgumentException("Outbound capability family [{$capability->family}] must match kinds key [{$family}].");
            }
        }
    }

    /** @return OutboundCapabilitiesArray */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'requires_permission' => $this->requiresPermission,
            'kinds' => array_map(
                static fn (OutboundCapabilityData $capability): array => $capability->toArray(),
                $this->kinds,
            ),
            'max_media_bytes' => $this->maxMediaBytes,
            'conversation_initiation' => $this->conversationInitiation,
        ];
    }
}
