<?php

namespace App\Services\FgtsDigital\Exceptions;

use RuntimeException;

final class FgtsDigitalException extends RuntimeException
{
    /** @param array<string, mixed> $context */
    public function __construct(
        string $message,
        public readonly string $codeKey,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
