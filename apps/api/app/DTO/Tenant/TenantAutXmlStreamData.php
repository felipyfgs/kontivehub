<?php

namespace App\DTO\Tenant;

final readonly class TenantAutXmlStreamData
{
    public function __construct(
        public bool $streamReady,
        public ?string $streamReason,
        public float $quietHours,
        public ?string $activatedAt,
        public ?string $readyAt,
    ) {}

    /** @return array<string, bool|float|string|null> */
    public function toArray(): array
    {
        return [
            'stream_ready' => $this->streamReady,
            'stream_reason' => $this->streamReason,
            'quiet_hours' => $this->quietHours,
            'activated_at' => $this->activatedAt,
            'ready_at' => $this->readyAt,
        ];
    }
}
