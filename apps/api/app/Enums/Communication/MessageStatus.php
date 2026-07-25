<?php

namespace App\Enums\Communication;

enum MessageStatus: string
{
    case Queued = 'QUEUED';
    case Accepted = 'ACCEPTED';
    case Sent = 'SENT';
    case Delivered = 'DELIVERED';
    case Read = 'READ';
    case Played = 'PLAYED';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';
    case Canceled = 'CANCELED';

    public function merge(self $incoming): self
    {
        if ($incoming === $this || $this === self::Played || $this === self::Canceled) {
            return $this;
        }

        if ($incoming === self::Canceled) {
            return in_array($this, [self::Queued, self::Accepted], true) ? $incoming : $this;
        }

        if (in_array($incoming, [self::Failed, self::Unknown], true)) {
            return $this->successfulRank() <= self::Accepted->successfulRank() ? $incoming : $this;
        }

        if (in_array($this, [self::Failed, self::Unknown], true)) {
            return $incoming->successfulRank() >= self::Sent->successfulRank() ? $incoming : $this;
        }

        return $incoming->successfulRank() > $this->successfulRank() ? $incoming : $this;
    }

    public function canTransitionTo(self $incoming): bool
    {
        return $this->merge($incoming) === $incoming;
    }

    private function successfulRank(): int
    {
        return match ($this) {
            self::Queued => 10,
            self::Accepted => 20,
            self::Sent => 30,
            self::Delivered => 40,
            self::Read => 50,
            self::Played => 60,
            self::Failed, self::Unknown, self::Canceled => 0,
        };
    }
}
