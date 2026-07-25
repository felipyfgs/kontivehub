<?php

namespace App\Exceptions;

use RuntimeException;

final class CommunicationTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly bool $retryable,
        public readonly ?int $httpStatus = null,
    ) {
        parent::__construct($errorCode);
    }
}
