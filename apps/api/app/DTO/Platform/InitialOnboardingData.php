<?php

namespace App\DTO\Platform;

final readonly class InitialOnboardingData
{
    public function __construct(
        public string $organizationName,
        public string $email,
        public string $password,
        public string $token,
    ) {}
}
