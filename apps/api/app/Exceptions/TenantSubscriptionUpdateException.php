<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

final class TenantSubscriptionUpdateException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        private readonly string $safeMessage,
    ) {
        parent::__construct('TENANT_SUBSCRIPTION_UPDATE_REJECTED');
    }

    public function safeMessage(): string
    {
        return $this->safeMessage;
    }
}
