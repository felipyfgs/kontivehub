<?php

namespace App\Exceptions;

use RuntimeException;

final class MeiAutomationTransportException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        public readonly int $httpStatus,
    ) {
        parent::__construct($errorCode);
    }
}
