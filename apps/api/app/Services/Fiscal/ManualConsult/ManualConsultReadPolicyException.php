<?php

namespace App\Services\Fiscal\ManualConsult;

use Symfony\Component\HttpKernel\Exception\HttpException;

final class ManualConsultReadPolicyException extends HttpException
{
    public function __construct(
        public readonly string $reasonCode,
        int $statusCode,
        string $message,
    ) {
        parent::__construct($statusCode, $message);
    }
}
