<?php

namespace App\Enums;

/**
 * Severidade de alerta sanitizado (caixa postal → inbox operacional).
 */
enum MailboxAlertSeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public static function fromHint(?string $hint, bool $dueSoon = false, bool $criticalCategory = false): self
    {
        if ($criticalCategory || $dueSoon) {
            return self::Critical;
        }

        return match (strtoupper((string) $hint)) {
            'CRITICAL', 'URGENTE', 'URGENT' => self::Critical,
            'HIGH', 'ALTA' => self::High,
            'LOW', 'BAIXA' => self::Low,
            default => self::Medium,
        };
    }
}
