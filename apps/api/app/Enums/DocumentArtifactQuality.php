<?php

namespace App\Enums;

/**
 * Qualidade do artefato XML custodiado (proveniência + integridade esperada).
 */
enum DocumentArtifactQuality: string
{
    case Original = 'ORIGINAL';
    case AutXmlOriginal = 'AUTXML_ORIGINAL';
    case AutXmlRedacted = 'AUTXML_REDACTED';

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::AutXmlOriginal => 'autXML original',
            self::AutXmlRedacted => 'autXML redigido (referências 999…)',
        };
    }

    public function isAutXmlDerived(): bool
    {
        return $this === self::AutXmlOriginal || $this === self::AutXmlRedacted;
    }

    /**
     * Preferência para canônico: original > autXML original > redigido.
     */
    public function canonicalRank(): int
    {
        return match ($this) {
            self::Original => 30,
            self::AutXmlOriginal => 20,
            self::AutXmlRedacted => 10,
        };
    }
}
