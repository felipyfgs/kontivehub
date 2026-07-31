<?php

namespace App\DTO\Communication;

use App\Enums\Communication\MessageAvailabilityState;

final readonly class MessageAvailabilityData
{
    public function __construct(
        public MessageAvailabilityState $state,
        public bool $recoverable,
    ) {}

    /** @return array{state:string,recoverable:bool} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'recoverable' => $this->recoverable,
        ];
    }
}
