<?php

namespace App\Services\FgtsDigital\Exceptions;

use App\DTO\FgtsDigital\FgtsDigitalReadinessData;
use App\Exceptions\ApiDomainException;
use Illuminate\Contracts\Debug\ShouldntReport;

final class FgtsDigitalException extends ApiDomainException implements ShouldntReport
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $responseData
     */
    public function __construct(
        string $message,
        public readonly string $codeKey,
        public readonly int $httpStatus = 422,
        public readonly array $context = [],
        ?array $responseData = null,
    ) {
        parent::__construct(
            $codeKey,
            $message,
            $httpStatus,
            $responseData ?? ['context' => $context],
        );
    }

    public static function readinessBlocked(
        FgtsDigitalReadinessData $readiness,
    ): self {
        return new self(
            $readiness->firstBlockerMessage(),
            $readiness->firstBlockerCode(),
            423,
            responseData: ['readiness' => $readiness->toArray()],
        );
    }
}
