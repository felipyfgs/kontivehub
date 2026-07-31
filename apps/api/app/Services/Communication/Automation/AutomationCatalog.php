<?php

namespace App\Services\Communication\Automation;

final class AutomationCatalog
{
    private const SCOPES = [
        'simples_mei:pgdasd',
        'simples_mei:pgmei',
        'dctfweb:dctfweb',
        'fgts:fgts',
    ];

    public function supports(string $moduleKey, string $submoduleKey): bool
    {
        return in_array($moduleKey.':'.$submoduleKey, self::SCOPES, true);
    }

    /** @return list<string> */
    public function scopes(): array
    {
        return self::SCOPES;
    }
}
