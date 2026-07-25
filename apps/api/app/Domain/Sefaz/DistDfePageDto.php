<?php

namespace App\Domain\Sefaz;

/**
 * Página de distribuição DistDFe (retDistDFeInt).
 *
 * cStat: 138 docs · 137 nenhum · 593 cert/CNPJ · 656 consumo indevido
 *
 * @param  list<DistDfeDocumentDto>  $documents
 */
final readonly class DistDfePageDto
{
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public int $ultNsu,
        public int $maxNsu,
        public array $documents,
        public ?string $rawXml = null,
    ) {}

    public function hasDocuments(): bool
    {
        return $this->cStat === '138' && $this->documents !== [];
    }

    public function isEmpty(): bool
    {
        return $this->cStat === '137' || ($this->cStat === '138' && $this->documents === []);
    }

    public function isAbuse(): bool
    {
        return $this->cStat === '656';
    }

    /** Certificado ou CNPJ consultado rejeitado (permanente até correção operacional). */
    public function isAuthError(): bool
    {
        return $this->cStat === '593';
    }

    public function isEndOfQueue(): bool
    {
        return $this->isEmpty() || $this->ultNsu >= $this->maxNsu;
    }
}
