<?php

namespace App\Domain\Sefaz;

/**
 * Resultado de NFeRecepcaoEvento4 (lote + evento).
 */
final readonly class ManifestationResultDto
{
    public function __construct(
        public string $cStat,
        public string $xMotivo,
        public ?string $protocol = null,
        public ?string $tpEvento = null,
        public ?string $eventCStat = null,
        public ?string $eventXMotivo = null,
        public ?string $rawXml = null,
    ) {}

    /** cStat de lote processado ou evento registrado (135/136). */
    public function isAccepted(): bool
    {
        $event = $this->eventCStat ?? $this->cStat;

        return in_array($event, ['135', '136'], true)
            || ($this->cStat === '128' && in_array($this->eventCStat, ['135', '136'], true));
    }

    public function effectiveCStat(): string
    {
        return $this->eventCStat ?? $this->cStat;
    }

    public function effectiveMotivo(): string
    {
        return $this->eventXMotivo ?: $this->xMotivo;
    }
}
