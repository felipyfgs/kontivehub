<?php

namespace App\Services\MeiAutomation;

use InvalidArgumentException;

final class MeiAutomationInputPolicy
{
    /** @var array<string, list<string>> */
    private const ALLOWED_FIELDS = [
        'fixture.health' => [],
        'pgmei.gerardaspdf' => ['cnpj', 'competencies', 'due_date'],
        'pgmei.gerardascodbarra' => ['cnpj', 'competencies', 'due_date'],
        'pgmei.atubeneficio' => ['cnpj', 'benefit_code', 'start_competence', 'end_competence', 'confirmation_ref'],
        'pgmei.dividaativa' => ['cnpj', 'calendar_year'],
        'ccmei.emitirccmei' => ['cnpj'],
        'ccmei.dadosccmei' => ['cnpj'],
        'ccmei.ccmeisitcadastral' => ['cnpj'],
        'dasnsimei.transdeclaracao' => [
            'cnpj',
            'calendar_year',
            'commerce_revenue',
            'service_revenue',
            'has_employee',
            'confirmation_ref',
        ],
        'dasnsimei.consultimadecrec' => ['cnpj', 'calendar_year', 'include_full_receipt'],
        'dasnsimei.gerardasexcesso' => ['cnpj', 'calendar_year', 'excess_amount', 'due_date'],
    ];

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function sanitize(string $operationKey, array $input): array
    {
        $operation = strtolower(trim($operationKey));
        $allowed = self::ALLOWED_FIELDS[$operation] ?? null;
        if ($allowed === null) {
            throw new InvalidArgumentException('Operação MEI sem política de input.');
        }

        $unknown = array_values(array_diff(array_keys($input), $allowed));
        if ($unknown !== []) {
            sort($unknown, SORT_STRING);
            throw new InvalidArgumentException('Campos não permitidos no input MEI: '.implode(', ', $unknown).'.');
        }

        $sanitized = [];
        foreach ($allowed as $field) {
            if (! array_key_exists($field, $input) || $input[$field] === null) {
                continue;
            }
            $sanitized[$field] = $this->normalize($field, $input[$field]);
        }

        return $sanitized;
    }

    private function normalize(string $field, mixed $value): mixed
    {
        return match ($field) {
            'cnpj' => $this->cnpj($value),
            'calendar_year' => $this->year($value),
            'competencies' => $this->competencies($value),
            'start_competence', 'end_competence' => $this->competence($value),
            'due_date' => $this->date($value),
            'has_employee', 'include_full_receipt' => $this->boolean($value, $field),
            'commerce_revenue', 'service_revenue', 'excess_amount' => $this->decimal($value, $field),
            default => $this->shortString($value, $field),
        };
    }

    private function cnpj(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('CNPJ MEI deve ser string.');
        }
        $normalized = strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $value));
        if (! preg_match('/^[A-Z0-9]{14}$/', $normalized)) {
            throw new InvalidArgumentException('CNPJ MEI deve ter 14 posições alfanuméricas.');
        }

        return $normalized;
    }

    private function year(mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new InvalidArgumentException('Ano-calendário MEI inválido.');
        }
        $year = (int) $value;
        if ($year < 2009 || $year > 2100) {
            throw new InvalidArgumentException('Ano-calendário MEI fora do intervalo aceito.');
        }

        return $year;
    }

    /** @return list<string> */
    private function competencies(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 12) {
            throw new InvalidArgumentException('Competências MEI devem ser uma lista de 1 a 12 itens.');
        }

        return array_map(fn (mixed $item): string => $this->competence($item), $value);
    }

    private function competence(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value)) {
            throw new InvalidArgumentException('Competência MEI inválida.');
        }

        return $value;
    }

    private function date(mixed $value): string
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new InvalidArgumentException('Data MEI inválida.');
        }
        $parts = array_map('intval', explode('-', $value));
        if (! checkdate($parts[1], $parts[2], $parts[0])) {
            throw new InvalidArgumentException('Data MEI inválida.');
        }

        return $value;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new InvalidArgumentException("Campo {$field} deve ser booleano.");
        }

        return $value;
    }

    private function decimal(mixed $value, string $field): string
    {
        if (! is_string($value) || ! preg_match('/^\d{1,12}(?:\.\d{1,2})?$/', $value)) {
            throw new InvalidArgumentException("Campo {$field} deve ser decimal em string.");
        }

        return $value;
    }

    private function shortString(mixed $value, string $field): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 120) {
            throw new InvalidArgumentException("Campo {$field} deve ser string curta.");
        }

        return trim($value);
    }
}
