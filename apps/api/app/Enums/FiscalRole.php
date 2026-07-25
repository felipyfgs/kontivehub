<?php

namespace App\Enums;

/**
 * Papel fiscal do estabelecimento/cliente no documento.
 * CT-e: ISSUER, SENDER, RECIPIENT, EXPEDITOR, RECEIVER, TAKER (+ AUTXML só comprova autorização).
 * NFS-e / legados: ISSUER, TAKER, INTERMEDIARY.
 */
enum FiscalRole: string
{
    case Issuer = 'ISSUER';
    case Taker = 'TAKER';
    case Intermediary = 'INTERMEDIARY';
    case Sender = 'SENDER';
    case Recipient = 'RECIPIENT';
    case Expeditor = 'EXPEDITOR';
    case Receiver = 'RECEIVER';
    /** Terceiro autorizado em autXML — não define direção do cliente. */
    case AutXml = 'AUTXML';

    public function label(): string
    {
        return match ($this) {
            self::Issuer => 'Emitente',
            self::Taker => 'Tomador',
            self::Intermediary => 'Intermediário',
            self::Sender => 'Remetente',
            self::Recipient => 'Destinatário',
            self::Expeditor => 'Expedidor',
            self::Receiver => 'Recebedor',
            self::AutXml => 'autXML',
        };
    }

    /**
     * Papéis elegíveis no DistDFe do próprio cliente (CT-e modelo 57).
     * Emitente não recebe o XML principal por esse canal.
     *
     * @return list<self>
     */
    public static function cteClientInterestRoles(): array
    {
        return [
            self::Sender,
            self::Recipient,
            self::Expeditor,
            self::Receiver,
            self::Taker,
        ];
    }

    public function isCteClientInterest(): bool
    {
        return in_array($this, self::cteClientInterestRoles(), true);
    }

    public function definesClientDirection(): bool
    {
        return $this !== self::AutXml;
    }
}
