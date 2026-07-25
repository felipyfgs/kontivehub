<?php

namespace App\Services\MeiAutomation;

use InvalidArgumentException;

final class MeiPortalResultValidator
{
    /** @param array<string, mixed>|null $result
     * @return array<string, mixed>|null
     */
    public function validate(string $operationKey, ?array $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return match (strtolower(trim($operationKey))) {
            'pgmei.gerardaspdf', 'pgmei.gerardascodbarra' => $this->das($result),
            'pgmei.dividaativa' => $this->debt($result),
            'dasnsimei.consultimadecrec' => $this->dasn($result),
            default => null,
        };
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function das(array $result): array
    {
        $competencies = $result['competencies'] ?? null;
        if (! is_array($competencies) || ! array_is_list($competencies)
            || $competencies === [] || count($competencies) > 12) {
            throw new InvalidArgumentException('Resultado DAS sem competências válidas.');
        }
        $normalized = [];
        foreach ($competencies as $competence) {
            if (! is_string($competence) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $competence) !== 1) {
                throw new InvalidArgumentException('Resultado DAS com competência inválida.');
            }
            $normalized[] = $competence;
        }

        return [
            'competencies' => array_values(array_unique($normalized)),
            'submitted' => ($result['submitted'] ?? false) === true,
            'barcode_available' => is_string($result['barcode'] ?? null)
                && preg_match('/^(?:\d{44}|\d{47}|\d{48})$/', (string) $result['barcode']) === 1,
            ...$this->versions($result),
        ];
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function debt(array $result): array
    {
        $years = $result['years'] ?? null;
        if (! is_array($years) || ! array_is_list($years) || count($years) > 25) {
            throw new InvalidArgumentException('Resultado de dívida ativa inválido.');
        }
        $normalized = [];
        foreach ($years as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Item de dívida ativa inválido.');
            }
            $year = $item['year'] ?? null;
            $count = $item['debt_count'] ?? null;
            $status = $this->shortString($item['status'] ?? null, 'status');
            if (! is_int($year) || $year < 2009 || $year > 2100
                || ! is_int($count) || $count < 0 || $count > 100000) {
                throw new InvalidArgumentException('Item de dívida ativa fora do contrato.');
            }
            $normalized[] = ['year' => $year, 'status' => $status, 'debt_count' => $count];
        }

        return ['years' => $normalized, ...$this->versions($result)];
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function dasn(array $result): array
    {
        $coverage = $this->coverage($result['coverage'] ?? null);
        $items = $result['declarations'] ?? null;
        if (! is_array($items) || ! array_is_list($items) || count($items) > 25) {
            throw new InvalidArgumentException('Resultado DASN-SIMEI inválido.');
        }
        $declarations = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException('Declaração DASN-SIMEI inválida.');
            }
            $year = $item['calendar_year'] ?? null;
            if (! is_int($year) || $year < 2009 || $year > 2100) {
                throw new InvalidArgumentException('Ano DASN-SIMEI inválido.');
            }
            $transmittedAt = $item['transmitted_at'] ?? null;
            if ($transmittedAt !== null
                && (! is_string($transmittedAt)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $transmittedAt) !== 1)) {
                throw new InvalidArgumentException('Data DASN-SIMEI inválida.');
            }
            $specialSituationDate = $item['special_situation_date'] ?? null;
            if ($specialSituationDate !== null
                && (! is_string($specialSituationDate)
                    || preg_match('/^\d{4}-\d{2}-\d{2}$/', $specialSituationDate) !== 1)) {
                throw new InvalidArgumentException('Data de situação especial DASN-SIMEI inválida.');
            }
            $artifactId = $item['receipt_artifact_id'] ?? null;
            if ($artifactId !== null
                && (! is_string($artifactId)
                    || preg_match('/^[0-9a-f-]{36}$/i', $artifactId) !== 1)) {
                throw new InvalidArgumentException('Artefato DASN-SIMEI inválido.');
            }
            $declarations[] = [
                'calendar_year' => $year,
                'status' => $this->shortString($item['status'] ?? null, 'status'),
                'transmitted_at' => $transmittedAt,
                'declaration_type' => $this->nullableShortString(
                    $item['declaration_type'] ?? null,
                    'declaration_type',
                    40,
                ),
                'special_situation' => $this->nullableShortString(
                    $item['special_situation'] ?? null,
                    'special_situation',
                ),
                'special_situation_date' => $specialSituationDate,
                'pending' => ($item['pending'] ?? false) === true,
                'coverage' => $this->coverage($item['coverage'] ?? null),
                'receipt_available' => ($item['receipt_available'] ?? false) === true,
                'receipt_artifact_id' => $artifactId,
            ];
        }

        return [
            'coverage' => $coverage,
            'declarations' => $declarations,
            ...$this->versions($result),
        ];
    }

    /** @param array<string, mixed> $result
     * @return array{parser_version:string,portal_version:string}
     */
    private function versions(array $result): array
    {
        return [
            'parser_version' => $this->shortString($result['parser_version'] ?? null, 'parser_version', 40),
            'portal_version' => $this->shortString($result['portal_version'] ?? null, 'portal_version', 40),
        ];
    }

    private function coverage(mixed $coverage): string
    {
        if (! is_string($coverage) || ! in_array($coverage, ['SUMMARY', 'FULL'], true)) {
            throw new InvalidArgumentException('Cobertura portal inválida.');
        }

        return $coverage;
    }

    private function shortString(mixed $value, string $field, int $max = 80): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > $max) {
            throw new InvalidArgumentException("Campo portal {$field} inválido.");
        }

        return trim($value);
    }

    private function nullableShortString(mixed $value, string $field, int $max = 80): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return $this->shortString($value, $field, $max);
    }
}
