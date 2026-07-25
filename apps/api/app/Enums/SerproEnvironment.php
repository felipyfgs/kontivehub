<?php

namespace App\Enums;

enum SerproEnvironment: string
{
    case Trial = 'TRIAL';
    case Production = 'PRODUCTION';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'Demonstração SERPRO',
            self::Production => 'Produção',
        };
    }

    public function isTrial(): bool
    {
        return $this === self::Trial;
    }
}
