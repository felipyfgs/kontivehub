<?php

namespace App\DTO\Serpro;

/**
 * @phpstan-type PowerRow array{
 *   power_code: string,
 *   system_code: string,
 *   service_code: ?string,
 *   valid_from: ?string,
 *   valid_to: ?string,
 *   status: string
 * }
 */
final class ProcuracaoLookupResult
{
    /**
     * @param  list<array{
     *   power_code: string,
     *   system_code: string,
     *   service_code: ?string,
     *   valid_from: ?string,
     *   valid_to: ?string,
     *   status: string
     * }>  $powers
     */
    public function __construct(
        public readonly bool $success,
        public readonly array $powers = [],
        /** @var list<string> */
        public readonly array $unmappedSystems = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $simulated = false,
        public readonly ?string $evidenceRef = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'success' => $this->success,
            'powers_count' => count($this->powers),
            'powers' => $this->powers,
            'unmapped_systems_count' => count($this->unmappedSystems),
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'simulated' => $this->simulated,
            'evidence_ref' => $this->evidenceRef,
        ];
    }
}
