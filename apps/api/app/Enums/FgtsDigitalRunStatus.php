<?php

namespace App\Enums;

enum FgtsDigitalRunStatus: string
{
    case Pending = 'PENDING';
    case Previewed = 'PREVIEWED';
    case Authorized = 'AUTHORIZED';
    case Running = 'RUNNING';
    case Succeeded = 'SUCCEEDED';
    case Reused = 'REUSED';
    case HumanChallengeRequired = 'HUMAN_CHALLENGE_REQUIRED';
    case ContractChanged = 'PORTAL_CONTRACT_CHANGED';
    case ReconciliationRequired = 'RECONCILIATION_REQUIRED';
    case Blocked = 'BLOCKED';
    case Failed = 'FAILED';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Succeeded,
            self::Reused,
            self::HumanChallengeRequired,
            self::ContractChanged,
            self::ReconciliationRequired,
            self::Blocked,
            self::Failed,
        ], true);
    }
}
