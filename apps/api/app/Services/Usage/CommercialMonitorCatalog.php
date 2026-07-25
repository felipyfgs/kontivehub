<?php

namespace App\Services\Usage;

/**
 * Catálogo de monitores SERPRO cobertos pela franquia comercial.
 * NFS-e, SEFAZ e autXML NÃO entram — permanecem em tempo real fora da franquia.
 */
final class CommercialMonitorCatalog
{
    /** @var list<string> */
    public const MONITOR_KEYS = [
        'sitfis',
        'simples_mei',
        'dctfweb',
        'installments',
        'mailbox',
        'declarations',
        'guides',
        'fgts',
        'registrations',
        'tax_processes',
    ];

    /**
     * Intervalo mínimo oficial/módulo (segundos) entre despachos manuais do mesmo cliente+monitor.
     * 0 = sem intervalo extra além do snapshot_ttl do módulo (quando houver).
     *
     * @var array<string, int>
     */
    private const MIN_INTERVAL_SECONDS = [
        'sitfis' => 86_400,
        'simples_mei' => 86_400,
        'dctfweb' => 3_600,
        'installments' => 86_400,
        'mailbox' => 3_600,
        'declarations' => 86_400,
        'guides' => 3_600,
        'fgts' => 86_400,
        'registrations' => 86_400,
        'tax_processes' => 86_400,
    ];

    public static function isCommercialMonitor(string $monitorKey): bool
    {
        return in_array(strtolower(trim($monitorKey)), self::MONITOR_KEYS, true);
    }

    /**
     * Mapeia system/service de run fiscal → monitor_key comercial.
     * Canais não-SERPRO-monitor retornam null (não consomem franquia).
     */
    public static function resolveMonitorKey(?string $systemCode, ?string $serviceCode, ?string $moduleKey = null): ?string
    {
        $module = strtolower(trim((string) $moduleKey));
        if ($module !== '' && self::isCommercialMonitor($module)) {
            return $module;
        }

        $service = strtoupper(trim((string) $serviceCode));
        $system = strtoupper(trim((string) $systemCode));

        $mapped = match (true) {
            $service === 'SITFIS' || str_contains($system, 'SITFIS') => 'sitfis',
            in_array($service, ['PGDASD', 'PGMEI', 'SIMPLES', 'SIMPLES_NACIONAL', 'MEI'], true) => 'simples_mei',
            str_contains($service, 'DCTF') || $service === 'MIT' => 'dctfweb',
            str_contains($service, 'PARCEL') || $service === 'INSTALLMENTS' => 'installments',
            $service === 'CAIXA_POSTAL' || $service === 'MAILBOX' || str_contains($service, 'MSGNAC') => 'mailbox',
            str_contains($service, 'DECLAR') => 'declarations',
            str_contains($service, 'GUIA') || $service === 'GUIDES' => 'guides',
            $service === 'FGTS' || str_contains($service, 'ESOCIAL') => 'fgts',
            $service === 'CADIN' || $service === 'REGISTRATIONS' => 'registrations',
            $service === 'TAX_PROCESSES' || str_contains($service, 'PROCESSO') => 'tax_processes',
            default => null,
        };

        if ($mapped !== null && self::isCommercialMonitor($mapped)) {
            return $mapped;
        }

        return null;
    }

    /**
     * Canais em tempo real que NUNCA consomem franquia comercial de monitor.
     */
    public static function isRealtimeNonFranchiseChannel(?string $systemCode, ?string $serviceCode, ?string $channel = null): bool
    {
        $blob = strtoupper(implode('|', array_filter([
            (string) $systemCode,
            (string) $serviceCode,
            (string) $channel,
        ])));

        if ($blob === '') {
            return false;
        }

        foreach (['NFSE', 'NFS-E', 'ADN', 'SEFAZ', 'AUTXML', 'DISTDFE', 'NFE', 'NFCE', 'CTE'] as $token) {
            if (str_contains($blob, $token)) {
                return true;
            }
        }

        return false;
    }

    public static function minIntervalSeconds(string $monitorKey): int
    {
        $key = strtolower(trim($monitorKey));

        return self::MIN_INTERVAL_SECONDS[$key]
            ?? (int) config('fiscal_monitoring.commercial.min_interval_seconds', 86_400);
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return self::MONITOR_KEYS;
    }
}
