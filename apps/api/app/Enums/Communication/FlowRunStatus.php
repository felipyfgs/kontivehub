<?php

namespace App\Enums\Communication;

enum FlowRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case WaitingInput = 'waiting_input';
    case WaitingDelay = 'waiting_delay';
    case WaitingOutbox = 'waiting_outbox';
    case Paused = 'paused';
    case HandedOff = 'handed_off';
    case Completed = 'completed';
    case Stopped = 'stopped';
    case Failed = 'failed';
    case Purged = 'purged';

    /** @return list<self> */
    public static function nonTerminal(): array
    {
        return [
            self::Pending,
            self::Running,
            self::WaitingInput,
            self::WaitingDelay,
            self::WaitingOutbox,
            self::Paused,
        ];
    }

    /** @return list<string> */
    public static function nonTerminalValues(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::nonTerminal());
    }

    public function isTerminal(): bool
    {
        return ! in_array($this, self::nonTerminal(), true);
    }

    public function canAdvance(): bool
    {
        return in_array($this, [
            self::Pending,
            self::Running,
            self::WaitingOutbox,
            self::WaitingDelay,
        ], true);
    }
}
