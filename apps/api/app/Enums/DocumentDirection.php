<?php

namespace App\Enums;

/**
 * Direção fiscal do documento no catálogo do escritório.
 * IN  = entrada (tomador/destinatário/interesse de custo)
 * OUT = saída (emitente/prestador)
 * UNKNOWN = não classificado
 */
enum DocumentDirection: string
{
    case In = 'IN';
    case Out = 'OUT';
    case Unknown = 'UNKNOWN';

    public function label(): string
    {
        return match ($this) {
            self::In => 'Entrada',
            self::Out => 'Saída',
            self::Unknown => 'Indefinida',
        };
    }

    /**
     * Deriva direção a partir do papel fiscal no estabelecimento.
     * ISSUER → OUT; papéis de interesse CT-e/NFS-e → IN; AUTXML não define; null → UNKNOWN.
     */
    public static function fromFiscalRole(?FiscalRole $role): self
    {
        return match ($role) {
            FiscalRole::Issuer => self::Out,
            FiscalRole::Taker,
            FiscalRole::Intermediary,
            FiscalRole::Sender,
            FiscalRole::Recipient,
            FiscalRole::Expeditor,
            FiscalRole::Receiver => self::In,
            FiscalRole::AutXml, null => self::Unknown,
        };
    }

    public static function tryFromRequest(?string $value): ?self
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtoupper(trim($value)));
    }
}
