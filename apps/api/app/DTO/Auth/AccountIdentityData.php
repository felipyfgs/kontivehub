<?php

namespace App\DTO\Auth;

final readonly class AccountIdentityData
{
    public function __construct(
        public string $name,
        public string $email,
    ) {}
}
