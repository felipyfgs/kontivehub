<?php

namespace App\Exceptions;

use RuntimeException;

final class CommunicationProfilePictureDownloadException extends RuntimeException
{
    public function __construct(public readonly string $safeCode, public readonly bool $retryable = false, public readonly ?int $httpStatus = null)
    {
        parent::__construct($safeCode);
    }
}
