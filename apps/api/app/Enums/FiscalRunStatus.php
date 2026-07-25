<?php

namespace App\Enums;

enum FiscalRunStatus: string
{
    case Queued = 'QUEUED';
    case Running = 'RUNNING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Skipped = 'SKIPPED';
    case Requeued = 'REQUEUED';
    case Blocked = 'BLOCKED';

    public function isOpen(): bool
    {
        return match ($this) {
            self::Queued, self::Running => true,
            default => false,
        };
    }

    /**
     * Estados em que execute() não deve reentrar (inclui REQUEUED: progresso salvo + continuação).
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Queued, self::Running => false,
            default => true,
        };
    }
}
