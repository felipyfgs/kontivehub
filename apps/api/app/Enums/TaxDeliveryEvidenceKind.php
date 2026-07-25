<?php

namespace App\Enums;

/**
 * Tipo de evidência de entrega declaratória.
 * Somente oficiais com protocolo/recibo são conclusivas.
 */
enum TaxDeliveryEvidenceKind: string
{
    case OfficialReceipt = 'OFFICIAL_RECEIPT';
    case OfficialProtocol = 'OFFICIAL_PROTOCOL';
    case OfficialResponse = 'OFFICIAL_RESPONSE';
    case InternalArtifact = 'INTERNAL_ARTIFACT';

    public function label(): string
    {
        return match ($this) {
            self::OfficialReceipt => 'Recibo oficial',
            self::OfficialProtocol => 'Protocolo oficial',
            self::OfficialResponse => 'Resposta oficial',
            self::InternalArtifact => 'Artefato interno',
        };
    }

    public function isOfficial(): bool
    {
        return $this !== self::InternalArtifact;
    }

    /**
     * Oficial só é conclusiva se houver número de protocolo/recibo
     * (avaliado no serviço; o kind sozinho não basta para INTERNAL).
     */
    public function canBeConclusive(): bool
    {
        return $this->isOfficial();
    }
}
