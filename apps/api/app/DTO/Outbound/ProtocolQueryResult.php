<?php

namespace App\DTO\Outbound;

/**
 * Resultado tipado da consulta de protocolo — sem envelope SOAP.
 */
final readonly class ProtocolQueryResult
{
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public ?string $consultedAccessKey = null,
        public ?string $returnedAccessKey = null,
        public ?string $protocol = null,
        public ?string $tpAmb = null,
        public bool $ambiguousTimeout = false,
        /** @var array<string, scalar|null> */
        public array $sanitized = [],
    ) {}

    public function isUnauthorizedConsumption(): bool
    {
        return $this->cStat === '656';
    }

    public function isNotFound(): bool
    {
        return $this->cStat === '217';
    }

    public function is562WithKey(): bool
    {
        return $this->cStat === '562' && $this->hasReturnedKey();
    }

    public function is562WithoutKey(): bool
    {
        return $this->cStat === '562' && ! $this->hasReturnedKey();
    }

    /**
     * SEFAZ revelou chave (562, 613 com chNFe no xMotivo, ou chNFe no XML).
     */
    public function hasReturnedKey(): bool
    {
        return $this->returnedAccessKey !== null && strlen($this->returnedAccessKey) >= 44;
    }

    /**
     * cStat de rejeição de chave/cNF com chave verdadeira utilizável (descoberta).
     */
    public function isKeyRevealReject(): bool
    {
        return $this->hasReturnedKey()
            && in_array($this->cStat, ['562', '613'], true);
    }

    public function isLimitedWithoutKey(): bool
    {
        if ($this->hasReturnedKey()) {
            return false;
        }

        return in_array($this->cStat, ['561', '613', '526'], true)
            || $this->is562WithoutKey();
    }

    public function isAuthorizedOnCandidate(): bool
    {
        return in_array($this->cStat, ['100', '101', '110', '150'], true);
    }
}
