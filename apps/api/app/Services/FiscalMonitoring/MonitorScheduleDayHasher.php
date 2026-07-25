<?php

namespace App\Services\FiscalMonitoring;

/**
 * Dia default estável (1–28) para política mensal office+monitor.
 * Distribui carteiras sem horário escolhido pelo usuário.
 *
 * Material: office key estável (PK ou uuid futuro) + monitor_key.
 */
final class MonitorScheduleDayHasher
{
    /**
     * @param  int|string  $officeKey  Identificador estável do office (id numérico ou uuid)
     */
    public static function defaultDay(int|string $officeKey, string $monitorKey): int
    {
        $monitor = strtolower(trim($monitorKey));
        if ($monitor === '') {
            throw new \InvalidArgumentException('monitor_key não pode ser vazio para hash de dia.');
        }

        $material = (string) $officeKey.'|'.$monitor;
        // 8 hex chars → 32-bit sem sinal; estável entre plataformas.
        $hash = hexdec(substr(hash('sha256', $material), 0, 8));

        return ($hash % 28) + 1;
    }
}
