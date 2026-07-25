<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalTrigger;

/**
 * Chaves idempotentes canônicas do núcleo fiscal.
 * Formato estável: office|client|system|service|operation|competence|trigger|slot
 */
final class FiscalIdempotency
{
    public static function runKey(
        int $officeId,
        int $clientId,
        string $systemCode,
        string $serviceCode,
        string $operationCode,
        ?string $competencePeriodKey,
        FiscalTrigger $trigger,
        string $slot,
    ): string {
        $parts = [
            (string) $officeId,
            (string) $clientId,
            strtoupper($systemCode),
            strtoupper($serviceCode),
            strtoupper($operationCode),
            $competencePeriodKey !== null && $competencePeriodKey !== '' ? $competencePeriodKey : '-',
            $trigger->value,
            $slot,
        ];

        $raw = implode('|', $parts);

        // Mantém legível até 160 chars; se exceder, usa hash com prefixo.
        if (strlen($raw) <= 160) {
            return $raw;
        }

        return substr(hash('sha256', $raw), 0, 160);
    }

    public static function scheduledSlot(\DateTimeInterface $at): string
    {
        return $at->format('YmdHi');
    }

    public static function eventSlot(string $eventHash): string
    {
        return 'evt:'.substr($eventHash, 0, 40);
    }

    public static function manualSlot(?string $correlationId = null): string
    {
        return 'man:'.($correlationId ?? bin2hex(random_bytes(8)));
    }

    public static function continuationSlot(int $parentRunId, int $attempt): string
    {
        return "cont:{$parentRunId}:{$attempt}";
    }

    public static function eventHash(
        int $officeId,
        string $systemCode,
        string $eventType,
        ?string $externalId,
        ?string $payloadDigest,
    ): string {
        $material = implode('|', [
            (string) $officeId,
            strtoupper($systemCode),
            strtoupper($eventType),
            $externalId ?? '',
            $payloadDigest ?? '',
        ]);

        return hash('sha256', $material);
    }

    public static function pendingLogicalKey(
        string $systemCode,
        string $serviceCode,
        string $code,
        ?string $competencePeriodKey = null,
    ): string {
        return implode(':', [
            strtoupper($systemCode),
            strtoupper($serviceCode),
            strtoupper($code),
            $competencePeriodKey ?? '-',
        ]);
    }

    /**
     * Chave de cache tenant-aware (nunca compartilhar entre offices).
     */
    public static function cacheKey(int $officeId, string $namespace, string ...$parts): string
    {
        $prefix = (string) config('fiscal_monitoring.cache.key_prefix', 'fiscal');

        return implode(':', array_merge([$prefix, (string) $officeId, $namespace], $parts));
    }

    /**
     * Lock de execução por identidade lógica (tenant incluso).
     */
    public static function runLockKey(int $officeId, string $idempotencyKey): string
    {
        return self::cacheKey($officeId, 'run-lock', hash('sha256', $idempotencyKey));
    }
}
