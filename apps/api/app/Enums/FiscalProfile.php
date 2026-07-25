<?php

namespace App\Enums;

use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

enum FiscalProfile: string
{
    case Dev = 'dev';
    case Trial = 'trial';
    case Production = 'production';

    public static function configured(): self
    {
        static $warned = false;
        $deprecated = (array) config('fiscal.deprecated_activation_keys_present', []);
        if (! $warned && $deprecated !== []) {
            $warned = true;
            Log::warning('fiscal.activation_flags_deprecated', ['keys' => array_values($deprecated)]);
        }

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
