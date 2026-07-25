<?php

namespace App\Support\FiscalDataModel;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Contexto privilegiado tipado para rotinas globais (jobs, console, plataforma).
 * Permite consultas de tenant sem CurrentOffice, com motivo auditável.
 * NÃO concede PLATFORM_ADMIN leitura fiscal implícita em HTTP — só contorna o
 * global scope quando o código entra explicitamente neste contexto.
 */
final class PrivilegedOfficeContext
{
    private static int $depth = 0;

    private static ?string $reason = null;

    public static function isOpen(): bool
    {
        return self::$depth > 0;
    }

    public static function reason(): ?string
    {
        return self::$reason;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(string $reason, callable $callback): mixed
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('PrivilegedOfficeContext exige motivo não vazio.');
        }

        self::enter($reason);

        try {
            return $callback();
        } finally {
            self::leave();
        }
    }

    public static function enter(string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('PrivilegedOfficeContext exige motivo não vazio.');
        }

        self::$depth++;
        self::$reason = $reason;

        if (self::$depth === 1) {
            Log::debug('privileged_office_context.enter', [
                'reason' => $reason,
            ]);
        }
    }

    public static function leave(): void
    {
        if (self::$depth <= 0) {
            return;
        }

        self::$depth--;
        if (self::$depth === 0) {
            Log::debug('privileged_office_context.leave', [
                'reason' => self::$reason,
            ]);
            self::$reason = null;
        }
    }

    /**
     * Uso em testes: garante depth zero.
     */
    public static function reset(): void
    {
        self::$depth = 0;
        self::$reason = null;
    }
}
