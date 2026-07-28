<?php

namespace App\DTO\Auth;

final readonly class PasswordConfirmationResult
{
    public function __construct(
        public int $windowMinutes,
        public int $secondsRemaining,
    ) {}

    /** @return array{confirmed: true, window_minutes: int, seconds_remaining: int} */
    public function toArray(): array
    {
        return [
            'confirmed' => true,
            'window_minutes' => $this->windowMinutes,
            'seconds_remaining' => $this->secondsRemaining,
        ];
    }
}
