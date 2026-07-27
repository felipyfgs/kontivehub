<?php

namespace App\Enums;

use InvalidArgumentException;

enum FiscalProfile: string
{
    case Dev = 'dev';
    case Trial = 'trial';
    case Production = 'production';

    public static function configured(): self
    {
        $value = strtolower(trim((string) config('fiscal.profile', self::Dev->value)));

        return self::tryFrom($value)
            ?? throw new InvalidArgumentException('FISCAL_PROFILE deve ser dev, trial ou production.');
    }

    public function serproEnvironment(): SerproEnvironment
    {
        return $this === self::Production
            ? SerproEnvironment::Production
            : SerproEnvironment::Trial;
    }

    public function usesNetwork(): bool
    {
        return $this !== self::Dev;
    }

    public function allows(FiscalOperationClass $operationClass, bool $officialTrialScenario = true): bool
    {
        if ($operationClass === FiscalOperationClass::FiscalMutation) {
            return false;
        }

        return match ($this) {
            self::Dev => true,
            self::Trial => $operationClass === FiscalOperationClass::Read || $officialTrialScenario,
            self::Production => $operationClass === FiscalOperationClass::Read,
        };
    }
}
