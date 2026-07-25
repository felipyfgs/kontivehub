<?php

namespace App\Services\Integra\TaxProcesses;

use RuntimeException;

/** Decodifica exclusivamente o layout oficial do e-Processo. */
final class TaxProcessesResponseCodec
{
    /** @return list<array<string, mixed>> */
    public function decode(mixed $dados): array
    {
        if (! is_array($dados)) {
            throw new RuntimeException('Resposta e-Processo fora do layout oficial: dados inválido.');
        }

        if ($dados === []) {
            return [];
        }

        $rows = array_is_list($dados) ? $dados : [$dados];
        $decoded = [];
        foreach ($rows as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Resposta e-Processo contém processo inválido.');
            }

            $number = $row['numeroDoProcesso'] ?? null;
            if (! is_string($number) && ! is_int($number)) {
                throw new RuntimeException('Resposta e-Processo inválida: numeroDoProcesso obrigatório.');
            }

            $number = trim((string) $number);
            if ($number === '' || mb_strlen($number) > 80) {
                throw new RuntimeException('Resposta e-Processo inválida: numeroDoProcesso obrigatório e limitado a 80 caracteres.');
            }

            $row['numeroDoProcesso'] = $number;
            $decoded[] = $row;
        }

        return $decoded;
    }
}
