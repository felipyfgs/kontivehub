<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\Enums\PgdasdOperationKind;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use RuntimeException;

/** Codec estrito do serviço oficial PGDASD/CONSDECLARACAO13. */
final class PgdasdConsDeclaracao13Codec
{
    /** @return array{anoCalendario:string}|array{periodoApuracao:string} */
    public function buildPayload(?string $anoCalendario, ?string $periodoApuracao): array
    {
        $ano = trim((string) $anoCalendario);
        $pa = trim((string) $periodoApuracao);

        if (($ano !== '') === ($pa !== '')) {
            throw new InvalidArgumentException(
                'CONSDECLARACAO13 exige exatamente um de anoCalendario ou periodoApuracao (XOR).'
            );
        }

        if ($ano !== '') {
            if (preg_match('/^\d{4}$/', $ano) !== 1) {
                throw new InvalidArgumentException('anoCalendario deve ter 4 dígitos.');
            }

            return ['anoCalendario' => $ano];
        }

        if (preg_match('/^\d{6}$/', $pa) !== 1 || (int) substr($pa, 4, 2) < 1 || (int) substr($pa, 4, 2) > 12) {
            throw new InvalidArgumentException('periodoApuracao deve estar no formato AAAAMM.');
        }

        return ['periodoApuracao' => $pa];
    }

    /**
     * @return array{
     *   query_year:?string,
     *   query_period:?string,
     *   periods:list<array{periodo_apuracao:string,period_key:string,operations:list<array<string,mixed>>}>,
     *   incomplete:bool
     * }
     */
    public function decodeDados(mixed $dados): array
    {
        $root = $this->coerceArray($dados);

        if (array_key_exists('periodos', $root)) {
            if (! is_array($root['periodos'])) {
                throw new RuntimeException('CONSDECLARACAO13: periodos deve ser uma lista.');
            }
            $year = $this->yearOrNull($root['anoCalendario'] ?? null);
            if ($year === null) {
                throw new RuntimeException('CONSDECLARACAO13: resposta anual sem anoCalendario válido.');
            }

            return $this->decodePeriodList($root['periodos'], $year, null);
        }

        if (array_key_exists('periodo', $root)) {
            if (! is_array($root['periodo'])) {
                throw new RuntimeException('CONSDECLARACAO13: periodo deve ser um objeto.');
            }
            $parsed = $this->parsePeriodRow($root['periodo']);

            return [
                'query_year' => substr($parsed['periodo_apuracao'], 0, 4),
                'query_period' => $parsed['periodo_apuracao'],
                'periods' => [$parsed['period']],
                'incomplete' => $parsed['incomplete'],
            ];
        }

        if (array_key_exists('periodoApuracao', $root) || array_key_exists('operacoes', $root)) {
            $parsed = $this->parsePeriodRow($root);

            return [
                'query_year' => substr($parsed['periodo_apuracao'], 0, 4),
                'query_period' => $parsed['periodo_apuracao'],
                'periods' => [$parsed['period']],
                'incomplete' => $parsed['incomplete'],
            ];
        }

        throw new RuntimeException('CONSDECLARACAO13: contêiner anual ou de PA ausente.');
    }

    /** @param array<string,mixed> $decoded */
    public function coversPeriodo(array $decoded, string $periodoApuracao): bool
    {
        if (preg_match('/^\d{6}$/', $periodoApuracao) !== 1) {
            return false;
        }

        $queryPeriod = $decoded['query_period'] ?? null;
        if (is_string($queryPeriod) && $queryPeriod !== '') {
            return hash_equals($queryPeriod, $periodoApuracao);
        }

        $queryYear = $decoded['query_year'] ?? null;

        return is_string($queryYear) && hash_equals($queryYear, substr($periodoApuracao, 0, 4));
    }

    /**
     * @param  list<mixed>  $rows
     * @return array{query_year:string,query_period:null,periods:list<array<string,mixed>>,incomplete:bool}
     */
    private function decodePeriodList(array $rows, string $year, ?string $queryPeriod): array
    {
        $periods = [];
        $incomplete = false;
        foreach ($rows as $row) {
            if (! is_array($row)) {
                $incomplete = true;

                continue;
            }
            $parsed = $this->parsePeriodRow($row);
            if (! str_starts_with($parsed['periodo_apuracao'], $year)) {
                $incomplete = true;
            }
            $periods[] = $parsed['period'];
            $incomplete = $incomplete || $parsed['incomplete'];
        }

        return [
            'query_year' => $year,
            'query_period' => $queryPeriod,
            'periods' => $periods,
            'incomplete' => $incomplete,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array{periodo_apuracao:string,period:array{periodo_apuracao:string,period_key:string,operations:list<array<string,mixed>>},incomplete:bool}
     */
    private function parsePeriodRow(array $row): array
    {
        $pa = preg_replace('/\D/', '', (string) ($row['periodoApuracao'] ?? '')) ?? '';
        $month = strlen($pa) === 6 ? (int) substr($pa, 4, 2) : 0;
        if (strlen($pa) !== 6 || $month < 1 || $month > 12) {
            throw new RuntimeException('CONSDECLARACAO13: periodoApuracao inválido na resposta.');
        }
        if (! array_key_exists('operacoes', $row) || ! is_array($row['operacoes'])) {
            throw new RuntimeException('CONSDECLARACAO13: operacoes deve ser uma lista, inclusive quando vazia.');
        }

        $operations = [];
        $incomplete = false;
        foreach ($row['operacoes'] as $operation) {
            if (! is_array($operation)) {
                $incomplete = true;

                continue;
            }
            $mapped = $this->mapOperation($operation, $pa);
            if ($mapped === null) {
                $incomplete = true;

                continue;
            }
            $operations[] = $mapped;
            $incomplete = $incomplete || ! $mapped['complete'];
        }

        return [
            'periodo_apuracao' => $pa,
            'period' => [
                'periodo_apuracao' => $pa,
                'period_key' => substr($pa, 0, 4).'-'.substr($pa, 4, 2),
                'operations' => $operations,
            ],
            'incomplete' => $incomplete,
        ];
    }

    /** @param array<string,mixed> $operation @return array<string,mixed>|null */
    private function mapOperation(array $operation, string $pa): ?array
    {
        $declaration = is_array($operation['indiceDeclaracao'] ?? null)
            ? $operation['indiceDeclaracao']
            : [];
        $das = is_array($operation['indiceDas'] ?? null)
            ? $operation['indiceDas']
            : (is_array($declaration['indiceDas'] ?? null) ? $declaration['indiceDas'] : []);

        $declarationNumber = $this->stringOrNull(
            $operation['numeroDeclaracao'] ?? $declaration['numeroDeclaracao'] ?? null
        );
        $dasNumber = $this->stringOrNull($operation['numeroDas'] ?? $das['numeroDas'] ?? null);
        $rawType = $this->stringOrNull($operation['tipoOperacao'] ?? null);
        $kind = PgdasdOperationKind::fromObservation($rawType, $declarationNumber !== null, $dasNumber !== null);
        if ($kind === null || ($declarationNumber !== null && $dasNumber !== null)) {
            return null;
        }

        $transmittedRaw = $this->stringOrNull(
            $operation['dataHoraTransmissao'] ?? $declaration['dataHoraTransmissao'] ?? null
        );
        // O serviço 13 responde em produção com as duas grafias observadas
        // para este campo. Ambas preservam o mesmo contrato semântico.
        $issuedRaw = $this->stringOrNull(
            $operation['dataHoraEmissaoDas']
                ?? $operation['datahoraEmissaoDas']
                ?? $das['dataHoraEmissaoDas']
                ?? $das['datahoraEmissaoDas']
                ?? null,
        );
        $transmittedAt = $this->parseSerproDateTime($transmittedRaw);
        $issuedAt = $this->parseSerproDateTime($issuedRaw);
        $complete = $kind === PgdasdOperationKind::Declaration
            ? $declarationNumber !== null && $transmittedAt !== null
            : $dasNumber !== null && $issuedAt !== null;

        $paymentLocated = null;
        if (array_key_exists('dasPago', $operation)) {
            $paymentLocated = filter_var($operation['dasPago'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        } elseif (array_key_exists('dasPago', $das)) {
            $paymentLocated = filter_var($das['dasPago'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        }

        $logicalKey = hash('sha256', implode('|', [
            $kind->value,
            $pa,
            $declarationNumber ?? '',
            $dasNumber ?? '',
        ]));

        return [
            'kind' => $kind->value,
            'raw_operation_type' => $rawType,
            'normalized_operation_type' => PgdasdOperationKind::normalizedOperationType($rawType, $kind),
            'declaration_number' => $declarationNumber,
            'das_number' => $dasNumber,
            'transmitted_at' => $transmittedAt,
            'issued_at' => $issuedAt,
            'malha' => $this->stringOrNull($operation['malha'] ?? $declaration['malha'] ?? null),
            'payment_located' => $paymentLocated,
            'logical_key' => $logicalKey,
            'complete' => $complete,
        ];
    }

    private function parseSerproDateTime(?string $raw): ?CarbonImmutable
    {
        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';
        if (strlen($digits) !== 14) {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('!YmdHis', $digits, 'America/Sao_Paulo');
            $errors = CarbonImmutable::getLastErrors();
            if ($parsed === false || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
                return null;
            }

            return $parsed;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function coerceArray(mixed $dados): array
    {
        if ($dados === null || $dados === '') {
            throw new RuntimeException('CONSDECLARACAO13: dados ausente; ausência fiscal não pode ser inferida.');
        }
        if (is_string($dados)) {
            $decoded = json_decode($dados, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('CONSDECLARACAO13: dados não é JSON válido.');
            }

            return $decoded;
        }
        if (! is_array($dados)) {
            throw new RuntimeException('CONSDECLARACAO13: dados em formato inválido.');
        }

        return $dados;
    }

    private function yearOrNull(mixed $value): ?string
    {
        $year = trim((string) $value);

        return preg_match('/^\d{4}$/', $year) === 1 ? $year : null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
