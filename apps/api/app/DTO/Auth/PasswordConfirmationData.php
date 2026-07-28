<?php

namespace App\DTO\Auth;

final readonly class PasswordConfirmationData
{
    public function __construct(
        public string $password,
    ) {}
}
