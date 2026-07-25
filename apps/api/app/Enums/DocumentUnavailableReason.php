<?php

namespace App\Enums;

/**
 * Motivo público de indisponibilidade do descritor de documento tenant-scoped.
 */
enum DocumentUnavailableReason: string
{
    case StructuredOnly = 'STRUCTURED_ONLY';
    case Processing = 'PROCESSING';
    case NotSupported = 'NOT_SUPPORTED';
    case NotProduction = 'NOT_PRODUCTION';
    case NotCollected = 'NOT_COLLECTED';
    case NotAvailable = 'NOT_AVAILABLE';
    case Expired = 'EXPIRED';
    case IntegrityRejected = 'INTEGRITY_REJECTED';

    public function label(): string
    {
        return match ($this) {
            self::StructuredOnly => 'Somente dados estruturados',
            self::Processing => 'Processando',
            self::NotSupported => 'Documento não suportado',
            self::NotProduction => 'Operação não produtiva',
            self::NotCollected => 'Documento ainda não coletado',
            self::NotAvailable => 'Documento não disponível',
            self::Expired => 'Documento expirado pela política de retenção',
            self::IntegrityRejected => 'Documento rejeitado pela verificação de integridade',
        };
    }
}
