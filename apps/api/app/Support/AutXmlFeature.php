<?php

namespace App\Support;

/**
 * Gate operacional do canal NFE_AUTXML_DISTDFE.
 * Desligado por padrão; allowlist vazia bloqueia todos os offices.
 */
final class AutXmlFeature
{
    public static function isGloballyEnabled(): bool
    {
        if (config('sefaz.autxml.kill_switch', false)) {
            return false;
        }

        return (bool) config('sefaz.autxml.enabled', false);
    }

    public static function isOfficeAllowed(int $officeId): bool
    {
        if (! self::isGloballyEnabled()) {
            return false;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('sefaz.autxml.office_allowlist', []);

        if ($allowlist === []) {
            return (bool) config('sefaz.autxml.allow_all_offices', false);
        }

        return in_array($officeId, $allowlist, true);
    }
}
