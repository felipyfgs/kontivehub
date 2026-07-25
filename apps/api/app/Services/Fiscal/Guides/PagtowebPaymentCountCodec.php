<?php

namespace App\Services\Fiscal\Guides;

use InvalidArgumentException;

/** Normaliza os filtros não sensíveis e o escalar retornado por PAGTOWEB 7.3. */
final class PagtowebPaymentCountCodec
{
    /** @param array<string, mixed> $filters @return array{business_data:array<string,mixed>,filter_summary:array<string,mixed>} */
    public function normalizeFilters(array $filters): array
    {
        $allowed = ['intervalo_data_arrecadacao', 'intervalo_valor_total_documento', 'codigo_receita_lista', 'codigo_tipo_documento_lista'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Filtro de contagem de pagamentos não permitido.');
        }
        $business = [];
        $summary = [];
        if (isset($filters['intervalo_data_arrecadacao'])) {
            $range = $filters['intervalo_data_arrecadacao'];
            if (! is_array($range)) {
                throw new InvalidArgumentException('Intervalo de arrecadação inválido.');
            }
            $initial = $range['data_inicial'] ?? $range['dataInicial'] ?? null;
            $final = $range['data_final'] ?? $range['dataFinal'] ?? null;
            if (! $this->validDate($initial) || ! $this->validDate($final) || $initial > $final) {
                throw new InvalidArgumentException('Intervalo de arrecadação inválido.');
            }
            $business['intervaloDataArrecadacao'] = ['dataInicial' => $initial, 'dataFinal' => $final];
            $summary['intervalo_data_arrecadacao'] = $business['intervaloDataArrecadacao'];
        }
        if (isset($filters['intervalo_valor_total_documento'])) {
            $range = $filters['intervalo_valor_total_documento'];
            if (! is_array($range)) {
                throw new InvalidArgumentException('Intervalo de valor inválido.');
            }
            $initial = $range['valor_inicial'] ?? $range['valorInicial'] ?? null;
            $final = $range['valor_final'] ?? $range['valorFinal'] ?? null;
            if (! is_numeric($initial) || ! is_numeric($final) || (float) $initial < 0 || (float) $initial > (float) $final) {
                throw new InvalidArgumentException('Intervalo de valor inválido.');
            }
            $business['intervaloValorTotalDocumento'] = ['valorInicial' => (float) $initial, 'valorFinal' => (float) $final];
            $summary['intervalo_valor_total_documento'] = $business['intervaloValorTotalDocumento'];
        }
        foreach (['codigo_receita_lista' => ['codigoReceitaLista', '/^[0-9]{1,4}$/'], 'codigo_tipo_documento_lista' => ['codigoTipoDocumentoLista', '/^[0-9]{1,2}$/']] as $input => [$output, $pattern]) {
            if (! isset($filters[$input])) {
                continue;
            }
            $values = $filters[$input];
            if (! is_array($values) || $values === [] || count($values) > 100 || array_filter($values, static fn ($value): bool => ! is_string($value) || ! preg_match($pattern, $value))) {
                throw new InvalidArgumentException('Lista de códigos inválida.');
            }
            $business[$output] = array_values(array_unique($values));
            $summary[$input] = $business[$output];
        }
        if ($business === []) {
            throw new InvalidArgumentException('Informe ao menos um filtro oficial para a contagem.');
        }

        return ['business_data' => $business, 'filter_summary' => $summary];
    }

    /** @return array{payment_count:int} */
    public function decodeCount(array|string|null $payload): array
    {
        if (is_array($payload) && array_key_exists('dados', $payload)) {
            $payload = $payload['dados'];
        }
        if (is_int($payload) || (is_string($payload) && preg_match('/^(0|[1-9][0-9]*)$/', trim($payload)))) {
            return ['payment_count' => (int) $payload];
        }
        throw new InvalidArgumentException('Resposta PAGTOWEB sem contagem válida.');
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }
}
