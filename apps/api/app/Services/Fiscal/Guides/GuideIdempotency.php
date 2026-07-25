<?php

namespace App\Services\Fiscal\Guides;

/**
 * Chaves canônicas de emissão de guia.
 * Formato: office|client|system|service|operation|competence|debit|slot
 */
final class GuideIdempotency
{
    public static function emissionKey(
        int $officeId,
        int $clientId,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $competencePeriodKey,
        ?string $debitRef,
        string $slot = 'current',
    ): string {
        $parts = [
            (string) $officeId,
            (string) $clientId,
            strtoupper($systemCode),
            strtoupper($serviceCode),
            strtoupper($operationCode),
            $competencePeriodKey !== null && $competencePeriodKey !== '' ? $competencePeriodKey : '-',
            $debitRef !== null && $debitRef !== '' ? $debitRef : '-',
            $slot,
        ];

        $raw = implode('|', $parts);

        if (strlen($raw) <= 160) {
            return $raw;
        }

        return substr(hash('sha256', $raw), 0, 160);
    }

    public static function reissueSlot(string $baseSlot, int $versionNumber): string
    {
        return $baseSlot.':v'.$versionNumber;
    }

    public static function logicalKey(
        int $clientId,
        string $systemCode,
        string $serviceCode,
        ?string $competencePeriodKey,
        ?string $debitRef,
    ): string {
        return implode(':', [
            (string) $clientId,
            strtoupper($systemCode),
            strtoupper($serviceCode),
            $competencePeriodKey ?? '-',
            $debitRef ?? '-',
        ]);
    }

    public static function paymentEvidenceDigest(string $source, string $externalId): string
    {
        return hash('sha256', strtoupper($source).'|'.$externalId);
    }
}
