<?php

namespace App\Services\Usage;

use RuntimeException;

final class ClientQuotaException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        public readonly int $current,
        public readonly int $maximum,
    ) {
        parent::__construct(match ($reason) {
            'SUBSCRIPTION_MISSING' => 'Escritório sem assinatura ativa; cadastro de clientes bloqueado.',
            default => sprintf(
                'Limite de clientes do plano atingido (%d/%d).',
                $current,
                $maximum,
            ),
        });
    }
}
