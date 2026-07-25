<?php

namespace App\Exceptions\Adn;

use RuntimeException;
use Throwable;

abstract class AdnException extends RuntimeException
{
    public function __construct(
        string $safeMessage,
        private readonly ?int $httpStatus = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, $httpStatus ?? 0, $previous);
    }

    public function httpStatus(): ?int
    {
        return $this->httpStatus;
    }
}
