<?php

namespace App\DTO\MeiAutomation;

use InvalidArgumentException;

final readonly class MeiAutomationJobRequest
{
    /** @param array<string, mixed> $input */
    public function __construct(
        public string $operationKey,
        public string $idempotencyKey,
        public string $requestFingerprint,
        public string $clientRef,
        public array $input = [],
    ) {
        if (! preg_match('/^[a-f0-9]{64}$/', $this->requestFingerprint)) {
            throw new InvalidArgumentException('Fingerprint da automação MEI inválido.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'operation_key' => strtolower(trim($this->operationKey)),
            'idempotency_key' => $this->idempotencyKey,
            'request_fingerprint' => $this->requestFingerprint,
            'client_ref' => $this->clientRef,
            'input' => $this->input,
        ];
    }
}
