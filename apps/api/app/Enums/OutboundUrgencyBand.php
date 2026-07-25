<?php

namespace App\Enums;

enum OutboundUrgencyBand: string
{
    case Planned = 'PLANNED';
    case Attention = 'ATTENTION';
    case Contingency = 'CONTINGENCY';
    case Overdue = 'OVERDUE';
    case Captured = 'CAPTURED';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planejado',
            self::Attention => 'Atenção',
            self::Contingency => 'Contingência',
            self::Overdue => 'Vencido',
            self::Captured => 'Capturado',
        };
    }

    public function isTerminalCapture(): bool
    {
        return $this === self::Captured;
    }

    public function requiresAssistedPrimary(): bool
    {
        return in_array($this, [self::Contingency, self::Overdue], true);
    }
}
