<?php

namespace App\Services\Outbound;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Kill switch do canal SVRS NF-e 55 (independente da flag env NFC-e).
 * Não apaga tentativas, objetos, aquisições nem posições nNF.
 */
final class SvrsNfe55KillSwitchService
{
    private const CACHE_KEY = 'sefaz.svrs_nfe55_xml.kill_switch.runtime';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isActive(): bool
    {
        if ((bool) config('sefaz.svrs_nfe55_xml.kill_switch', false)) {
            return true;
        }

        return (bool) Cache::get(self::CACHE_KEY, false);
    }

    public function activate(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forever(self::CACHE_KEY, true);
        $this->audit->record(
            'svrs_nfe55.kill_switch.on',
            'SUCCESS',
            null,
            ['reason' => mb_substr($reason, 0, 500)],
            $userId,
            $officeId,
        );
    }

    public function deactivate(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->audit->record(
            'svrs_nfe55.kill_switch.off',
            'SUCCESS',
            null,
            ['reason' => mb_substr($reason, 0, 500)],
            $userId,
            $officeId,
        );
    }
}
