<?php

namespace App\Enums;

/**
 * Classificação de proveniência para impedir promoção implícita de demo/shadow.
 */
enum SerproDataSegregationClass: string
{
    case Production = 'PRODUCTION';
    case Demo = 'DEMO';
    case Shadow = 'SHADOW';
    case Fake = 'FAKE';
    case TrialSimulated = 'TRIAL_SIMULATED';
    case HistoricalUnverified = 'HISTORICAL_UNVERIFIED';

    public function maySatisfyRealDriver(): bool
    {
        return $this === self::Production;
    }
}
