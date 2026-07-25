<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\PgmeiDebtState;
use JsonException;
use RuntimeException;

/** Codec estrito do contrato oficial PGMEI/DIVIDAATIVA24/1.0. */
final class PgmeiDividaAtiva24Codec
{
    /** @return array{anoCalendario:string} */
    public function buildPayload(int|string $anoCalendario): array
    {
        return ['anoCalendario' => PgmeiYear::assertFormat((string) $anoCalendario)];
    }

    /**
     * @return array{
     *   year:int,
     *   calendar_year:int,
     *   state:string,
     *   items:list<array{
     *     logical_key:string,
     *     periodo_apuracao:string,
     *     period_key:string,
     *     tributo:string,
     *     amount_cents:int,
     *     ente_federado:string,
     *     situacao_debito:string
     *   }>,
     *   debt_count:int,
     *   items_count:int,
     *   total_cents:int,
     *   response_digest:string,
     *   digest:string
     * }
     */
    public function decodeResponse(IntegraResponse $response, int|string $expectedYear): array
    {
        if ($response->dados === null) {
            throw new RuntimeException(
                'DIVIDAATIVA24: response.dados ausente; ausência de dívida não pode ser inferida.'
            );
        }

        return $this->decodeDados($response->dados, $expectedYear);
    }

    /**
     * Decodifica exclusivamente a lista oficial de objetos `Debito` em `response.dados`.
     * Um array vazio explícito é uma consulta válida sem dívida; null/string vazia não é.
     *
     * @return array{
     *   year:int,
     *   calendar_year:int,
     *   state:string,
     *   items:list<array<string,mixed>>,
     *   debt_count:int,
     *   items_count:int,
     *   total_cents:int,
     *   response_digest:string,
     *   digest:string
     * }
     */
    public function decodeDados(mixed $dados, int|string $expectedYear): array
    {
        $year = PgmeiYear::assertValid($expectedYear);
        $rows = $this->coerceOfficialList($dados);

        /** @var list<array<string,mixed>> $parsed */
        $parsed = [];
        $total = 0;
        foreach ($rows as $index => $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException("DIVIDAATIVA24: débito {$index} deve ser um objeto.");
            }

            $item = $this->parseItem($row, $year, $index);
            if ($item['amount_cents'] > PHP_INT_MAX - $total) {
                throw new RuntimeException('DIVIDAATIVA24: soma monetária excede o limite suportado.');
            }
            $total += $item['amount_cents'];
            $parsed[] = $item;
        }

        usort($parsed, static function (array $left, array $right): int {
            return strcmp(
                self::canonicalItem($left),
                self::canonicalItem($right),
            );
        });

        $occurrences = [];
        $items = [];
        foreach ($parsed as $item) {
            $baseKey = hash('sha256', self::canonicalItem($item));
            $occurrence = ($occurrences[$baseKey] ?? 0) + 1;
            $occurrences[$baseKey] = $occurrence;
            $item['logical_key'] = hash('sha256', $baseKey.'|'.$occurrence);
            $items[] = $item;
        }

        $count = count($items);
        $state = $count > 0
            ? PgmeiDebtState::HasActiveDebt
            : PgmeiDebtState::NoActiveDebt;
        $digest = hash('sha256', json_encode([
            'anoCalendario' => (string) $year,
            'debitos' => array_map(
                static fn (array $item): array => array_diff_key($item, ['logical_key' => true]),
                $items,
            ),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'year' => $year,
            'calendar_year' => $year,
            'state' => $state->value,
            'items' => $items,
            'debt_count' => $count,
            'items_count' => $count,
            'total_cents' => $total,
            'response_digest' => $digest,
            'digest' => $digest,
        ];
    }

    /**
     * Compatibilidade controlada com o transporte: nunca procura listas em campos inventados.
     *
     * @param  array<string,mixed>  $body
     */
    public function extractDados(array $body): mixed
    {
        if (array_key_exists('dados', $body)) {
            return $body['dados'];
        }
        if (isset($body['response']) && is_array($body['response'])
            && array_key_exists('dados', $body['response'])) {
            return $body['response']['dados'];
        }

        throw new RuntimeException('DIVIDAATIVA24: campo response.dados ausente.');
    }

    /** @return list<mixed> */
    private function coerceOfficialList(mixed $dados): array
    {
        if (is_string($dados)) {
            $json = trim($dados);
            if ($json === '' || strcasecmp($json, 'null') === 0) {
                throw new RuntimeException(
                    'DIVIDAATIVA24: dados vazio não confirma ausência de dívida.'
                );
            }
            try {
                $dados = json_decode($json, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            } catch (JsonException $e) {
                throw new RuntimeException('DIVIDAATIVA24: dados não é JSON válido.', 0, $e);
            }
        }

        if (! is_array($dados) || ! array_is_list($dados)) {
            throw new RuntimeException('DIVIDAATIVA24: response.dados deve ser uma lista de débitos.');
        }

        return $dados;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{
     *   periodo_apuracao:string,
     *   period_key:string,
     *   tributo:string,
     *   amount_cents:int,
     *   ente_federado:string,
     *   situacao_debito:string
     * }
     */
    private function parseItem(array $row, int $expectedYear, int $index): array
    {
        foreach (['periodoApuracao', 'tributo', 'valor', 'enteFederado', 'situacaoDebito'] as $field) {
            if (! array_key_exists($field, $row)) {
                throw new RuntimeException("DIVIDAATIVA24: {$field} ausente no débito {$index}.");
            }
        }

        $pa = trim((string) $row['periodoApuracao']);
        if (preg_match('/^\d{6}$/', $pa) !== 1) {
            throw new RuntimeException("DIVIDAATIVA24: periodoApuracao inválido no débito {$index}.");
        }
        $month = (int) substr($pa, 4, 2);
        if ($month < 1 || $month > 12 || (int) substr($pa, 0, 4) !== $expectedYear) {
            throw new RuntimeException(
                "DIVIDAATIVA24: periodoApuracao não pertence ao ano consultado no débito {$index}."
            );
        }

        return [
            'periodo_apuracao' => $pa,
            'period_key' => substr($pa, 0, 4).'-'.substr($pa, 4, 2),
            'tributo' => $this->requiredString($row['tributo'], 120, 'tributo', $index),
            'amount_cents' => $this->toCents($row['valor'], $index),
            'ente_federado' => $this->requiredString(
                $row['enteFederado'],
                120,
                'enteFederado',
                $index,
            ),
            'situacao_debito' => $this->requiredString(
                $row['situacaoDebito'],
                255,
                'situacaoDebito',
                $index,
            ),
        ];
    }

    private function toCents(mixed $raw, int $index): int
    {
        if (is_int($raw)) {
            $lexeme = (string) $raw;
        } elseif (is_float($raw) && is_finite($raw)) {
            // Apenas serializa o token numérico para texto; não executa aritmética binária.
            $encoded = json_encode($raw, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            if (! is_string($encoded)) {
                throw new RuntimeException("DIVIDAATIVA24: valor inválido no débito {$index}.");
            }
            $lexeme = $encoded;
        } elseif (is_string($raw)) {
            $lexeme = trim($raw);
        } else {
            throw new RuntimeException("DIVIDAATIVA24: valor inválido no débito {$index}.");
        }

        $lexeme = preg_replace('/^R\$\s*/iu', '', trim($lexeme)) ?? '';
        $lexeme = preg_replace('/\s+/u', '', $lexeme) ?? '';
        if ($lexeme === '' || str_starts_with($lexeme, '-')) {
            throw new RuntimeException("DIVIDAATIVA24: valor negativo ou vazio no débito {$index}.");
        }

        if (preg_match('/^\d+(?:\.\d+)?[eE][+-]?\d+$/', $lexeme) === 1) {
            $lexeme = $this->expandScientific($lexeme, $index);
        }

        if (preg_match('/^\d{1,3}(?:\.\d{3})+,\d{1,2}$/', $lexeme) === 1) {
            $lexeme = str_replace(['.', ','], ['', '.'], $lexeme);
        } elseif (preg_match('/^\d+,\d{1,2}$/', $lexeme) === 1) {
            $lexeme = str_replace(',', '.', $lexeme);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d{1,2}$/', $lexeme) === 1) {
            $lexeme = str_replace(',', '', $lexeme);
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $lexeme) !== 1) {
            throw new RuntimeException("DIVIDAATIVA24: valor monetário ambíguo no débito {$index}.");
        }

        [$whole, $fraction] = array_pad(explode('.', $lexeme, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 2, '0');

        $maxWhole = (string) intdiv(PHP_INT_MAX - 99, 100);
        if (strlen($whole) > strlen($maxWhole)
            || (strlen($whole) === strlen($maxWhole) && strcmp($whole, $maxWhole) > 0)) {
            throw new RuntimeException("DIVIDAATIVA24: valor excede o limite suportado no débito {$index}.");
        }

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function expandScientific(string $lexeme, int $index): string
    {
        if (preg_match('/^(\d+)(?:\.(\d+))?[eE]([+-]?\d+)$/', $lexeme, $matches) !== 1) {
            throw new RuntimeException("DIVIDAATIVA24: notação científica inválida no débito {$index}.");
        }

        $integer = $matches[1];
        $fraction = $matches[2] ?? '';
        $exponent = (int) $matches[3];
        if (abs($exponent) > 30) {
            throw new RuntimeException("DIVIDAATIVA24: expoente fora do limite no débito {$index}.");
        }

        $digits = $integer.$fraction;
        $point = strlen($integer) + $exponent;
        if ($point <= 0) {
            return '0.'.str_repeat('0', -$point).$digits;
        }
        if ($point >= strlen($digits)) {
            return $digits.str_repeat('0', $point - strlen($digits));
        }

        return substr($digits, 0, $point).'.'.substr($digits, $point);
    }

    private function requiredString(mixed $value, int $max, string $field, int $index): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException("DIVIDAATIVA24: {$field} inválido no débito {$index}.");
        }
        $text = trim((string) $value);
        if ($text === '' || mb_strlen($text) > $max) {
            throw new RuntimeException("DIVIDAATIVA24: {$field} vazio ou longo demais no débito {$index}.");
        }

        return $text;
    }

    /** @param array<string,mixed> $item */
    private static function canonicalItem(array $item): string
    {
        return json_encode([
            'periodoApuracao' => $item['periodo_apuracao'],
            'tributo' => $item['tributo'],
            'valorCentavos' => $item['amount_cents'],
            'enteFederado' => $item['ente_federado'],
            'situacaoDebito' => $item['situacao_debito'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
