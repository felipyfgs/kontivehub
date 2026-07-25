<?php

namespace App\Services\Outbound;

use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Kill switch específico do canal SVRS NFC-e XML.
 * Bloqueia novos GET/POST e jobs; não apaga tentativas, objetos, aquisições nem posições nNF.
 */
final class SvrsNfceKillSwitchService
{
    private const CACHE_KEY = 'sefaz.svrs_nfce_xml.kill_switch.runtime';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isActive(): bool
    {
        if ((bool) config('sefaz.svrs_nfce_xml.kill_switch', false)) {
            return true;
        }

        return (bool) Cache::get(self::CACHE_KEY, false);
    }

    public function activate(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forever(self::CACHE_KEY, true);
        $this->audit->record(
            'svrs_nfce.kill_switch.on',
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
            'svrs_nfce.kill_switch.off',
            'SUCCESS',
            null,
            ['reason' => mb_substr($reason, 0, 500)],
            $userId,
            $officeId,
        );
    }

    /**
     * @return array{active: bool, source: string|null}
     */
    public function status(): array
    {
        $env = (bool) config('sefaz.svrs_nfce_xml.kill_switch', false);
        $runtime = (bool) Cache::get(self::CACHE_KEY, false);

        return [
            'active' => $env || $runtime,
            'source' => $env ? 'config' : ($runtime ? 'runtime' : null),
        ];
    }
}
