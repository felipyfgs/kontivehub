<?php

namespace App\Enums;

/**
 * Estado operacional da declaração PGDAS-D para o PA esperado (fail-closed).
 * Representa somente a entrega do PA — não incorpora malha, MAED nem pagamento de DAS.
 */
enum PgdasdDeclarationState: string
{
    case Current = 'CURRENT';
    case DueWithinDeadline = 'DUE_WITHIN_DEADLINE';
    case OverdueNotFound = 'OVERDUE_NOT_FOUND';
    case Unverified = 'UNVERIFIED';

    public function label(): string
    {
        return match ($this) {
            self::Current => 'Em dia',
            self::DueWithinDeadline => 'No prazo',
            self::OverdueNotFound => 'Atrasado',
            self::Unverified => 'Não verificado',
        };
    }

    /**
     * Mapeamento canônico para a situação fiscal agregada (KPI/filtros).
     */
    public function toFiscalSituation(): FiscalSituation
    {
        return match ($this) {
            self::Current => FiscalSituation::UpToDate,
            self::DueWithinDeadline => FiscalSituation::Pending,
            self::OverdueNotFound => FiscalSituation::Attention,
            self::Unverified => FiscalSituation::Unknown,
        };
    }
}
