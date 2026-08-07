<?php

namespace App\DTO\Communication;

final readonly class MessageBatchItemContext
{
    public function __construct(
        public int $batchId,
        public string $gatewayBatchId,
        public int $position,
        public int $size,
    ) {
        if ($this->batchId < 1
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,127}$/', $this->gatewayBatchId) !== 1
            || $this->position < 0
            || $this->size < 2
            || $this->size > 10
            || $this->position >= $this->size) {
            throw new \InvalidArgumentException('Contexto de item de lote inválido.');
        }
    }
}
