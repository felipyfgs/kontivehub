<?php

namespace App\Enums;

/** Resultado operacional de uma execução de monitoramento. */
enum FiscalRunResult: string
{
    case Success = 'SUCCESS';
    case Partial = 'PARTIAL';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
    case Requeued = 'REQUEUED';
    case Blocked = 'BLOCKED';

    public function label(): string
    {
        return match ($this) {
            self::Success => 'Sucesso',
            self::Partial => 'Parcial',
            self::Failed => 'Falha',
            self::Skipped => 'Ignorado',
            self::Requeued => 'Reencaminhado',
            self::Blocked => 'Bloqueado',
        };
    }

    public function isFinal(): bool
    {
        return match ($this) {
            self::Requeued => false,
            default => true,
        };
    }
}
