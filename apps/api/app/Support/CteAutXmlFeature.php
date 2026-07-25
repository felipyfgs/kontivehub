<?php

namespace App\Support;

/**
 * Gate operacional do canal CTE_AUTXML_DISTDFE.
 * Desligado por padrão; requer também sefaz.cte_enabled; allowlist vazia bloqueia todos.
 */
final class CteAutXmlFeature
{
    public static function isGloballyEnabled(): bool
    {
        if (config('sefaz.cte_autxml.kill_switch', false)) {
            return false;
        }

        if (! (bool) config('sefaz.cte_enabled', false)) {
            return false;
        }

        return (bool) config('sefaz.cte_autxml.enabled', false);
    }

    public static function isOfficeAllowed(int $officeId): bool
    {
        if (! self::isGloballyEnabled()) {
            return false;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('sefaz.cte_autxml.office_allowlist', []);

        if ($allowlist === []) {
            return (bool) config('sefaz.cte_autxml.allow_all_offices', false);
        }

        return in_array($officeId, $allowlist, true);
    }
}
