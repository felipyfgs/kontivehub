<?php

namespace App\Support\Work;

use App\Models\Tenant;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Timezone IANA do escritório (processos/filas/prazos).
 * Fallback: America/Sao_Paulo.
 */
final class TenantTimezone
{
    public const DEFAULT = 'America/Sao_Paulo';

    public static function for(Tenant $tenant): string
    {
        $tz = $tenant->timezone ?? $tenant->deadline_timezone ?? null;
        if (is_string($tz) && $tz !== '' && self::isValid($tz)) {
            return $tz;
        }

        return self::DEFAULT;
    }

    public static function isValid(string $timezone): bool
    {
        try {
            new DateTimeZone($timezone);

            return true;
        } catch (\Exception) {
            return false;
        }
    }

    public static function assertValid(string $timezone): string
    {
        if (! self::isValid($timezone)) {
            throw new InvalidArgumentException("Timezone IANA inválido: {$timezone}");
        }

        return $timezone;
    }
}
