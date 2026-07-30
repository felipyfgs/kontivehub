<?php

namespace App\DTO\Communication;

use InvalidArgumentException;

final readonly class CommunicationMaintenanceContext
{
    public function __construct(
        public int $tenantId,
        public int $inboxId,
        public string $operationId,
        public bool $execute = false,
        public ?int $actorId = null,
    ) {
        if ($this->tenantId < 1 || $this->inboxId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $this->operationId) !== 1) {
            throw new InvalidArgumentException('Contexto de manutenção inválido.');
        }
        if ($this->execute && ($this->actorId ?? 0) < 1) {
            throw new InvalidArgumentException('Execução exige ator privilegiado explícito.');
        }
    }
}
