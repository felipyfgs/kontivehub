<?php

namespace App\Contracts;

use App\DTO\Mailbox\CaixaPostalIndicatorResult;

interface CaixaPostalIndicatorClient
{
    /** @param array<string, mixed> $context */
    public function getIndicator(array $context = []): CaixaPostalIndicatorResult;
}
