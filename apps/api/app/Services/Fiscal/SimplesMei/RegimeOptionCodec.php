<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;

/** Codec estrito de REGIMEAPURACAO / CONSULTAROPCAOREGIME103. */
final class RegimeOptionCodec
{
    public const OPERATION_KEY = 'regimeapuracao.consultaropcaoregime';

    /** @return array{anoCalendario:int} */
    public function buildPayload(int|string $year): array
    {
        return ['anoCalendario' => $this->year($year)];
    }

    /** @param array<string, mixed> $body @return array{calendar_year:int,regime_apuracao:string} */
    public function decode(array $body, int|string $expectedYear): array
    {
        $root = $body['dados'] ?? $body['data'] ?? $body;
        if (is_string($root)) {
            $root = json_decode($root, true);
        }
        if (! is_array($root)) {
            throw new InvalidArgumentException('Resposta Regime 103 inválida.');
        }

        $year = $this->year($root['anoCalendario'] ?? $expectedYear);
        if ($year !== $this->expectedYear($expectedYear)) {
            throw new InvalidArgumentException('Ano-calendário divergente na resposta Regime 103.');
        }
        $option = strtoupper(trim((string) ($root['regimeEscolhido'] ?? $root['regimeApurado'] ?? '')));
        if (! in_array($option, ['COMPETENCIA', 'CAIXA'], true)) {
            throw new InvalidArgumentException('Opção de regime inválida na resposta Regime 103.');
        }

        return ['calendar_year' => $year, 'regime_apuracao' => $option];
    }

    private function year(int|string $year): int
    {
        $raw = trim((string) $year);
        if (preg_match('/^\d{4}$/', $raw) !== 1 || (int) $raw < 2000 || (int) $raw > 2100) {
            throw new InvalidArgumentException('anoCalendario inválido para CONSULTAROPCAOREGIME103.');
        }

        return (int) $raw;
    }

    private function expectedYear(int|string $year): int
    {
        $raw = trim((string) $year);
        if (preg_match('/^(\d{4})(?:-\d{2})?$/', $raw, $matches) !== 1) {
            throw new InvalidArgumentException('Período inválido para CONSULTAROPCAOREGIME103.');
        }

        return $this->year($matches[1]);
    }
}
