<?php

namespace App\Services\Fiscal\Declarations;

use Illuminate\Validation\ValidationException;

/** Validação server-side dos parâmetros públicos antes de qualquer dispatch. */
final class DeclarationOperationInputValidator
{
    private const MAX_JSON_BYTES = 1_048_576;

    private const MAX_DEPTH = 20;

    /** @var list<string> */
    private const FORBIDDEN_KEYS = [
        'officeid',
        'operationkey',
        'idsistema',
        'idservico',
        'versaosistema',
        'contratante',
        'autorpedidodados',
        'contribuinte',
        'contractorcnpj',
        'authoridentity',
        'procuradortoken',
        'autenticarprocuradortoken',
    ];

    public function __construct(
        private readonly DeclarationOperationRegistry $registry,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    public function validate(string $operationKey, array $params): array
    {
        $encoded = json_encode($params, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > self::MAX_JSON_BYTES) {
            $this->fail('params', 'Os dados excedem o limite de 1 MB.');
        }
        $this->assertNoTechnicalKeys($params);

        $schema = $this->registry->publicParamsFor($operationKey);
        $allowed = array_column($schema, null, 'name');
        foreach ($params as $name => $_value) {
            if (! is_string($name) || ! isset($allowed[$name])) {
                $this->fail('params.'.(string) $name, 'Parâmetro não permitido para esta ação.');
            }
        }

        $normalized = [];
        foreach ($schema as $field) {
            $name = $field['name'];
            $hasValue = array_key_exists($name, $params)
                && $params[$name] !== null
                && $params[$name] !== '';
            if (! $hasValue) {
                if ($field['required']) {
                    $this->fail("params.{$name}", 'Parâmetro obrigatório.');
                }

                continue;
            }

            $normalized[$name] = $this->normalizeValue($name, $field['type'], $params[$name]);
        }

        if ($operationKey === 'pgdasd.consdeclaracao') {
            $hasYear = isset($normalized['calendar_year']);
            $hasPeriod = isset($normalized['period_key']);
            if ($hasYear === $hasPeriod) {
                $this->fail('params', 'Informe exatamente um entre ano-calendário e competência.');
            }
        }

        return $normalized;
    }

    private function normalizeValue(string $name, string $type, mixed $value): mixed
    {
        return match ($type) {
            'integer' => $this->integer($name, $value),
            'month' => $this->month($name, $value),
            'date' => $this->date($name, $value),
            'object' => $this->object($name, $value),
            'array' => $this->arrayValue($name, $value),
            'base64' => $this->base64($name, $value),
            default => $this->string($name, $value),
        };
    }

    private function integer(string $name, mixed $value): int
    {
        if (! is_int($value) && ! (is_string($value) && preg_match('/^-?\d+$/', $value) === 1)) {
            $this->fail("params.{$name}", 'Informe um número inteiro válido.');
        }

        return (int) $value;
    }

    private function month(string $name, mixed $value): string
    {
        $value = $this->string($name, $value);
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $value) !== 1) {
            $this->fail("params.{$name}", 'Use o formato AAAA-MM.');
        }

        return $value;
    }

    private function date(string $name, mixed $value): string
    {
        $value = $this->string($name, $value);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            $this->fail("params.{$name}", 'Use uma data válida no formato AAAA-MM-DD.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    private function object(string $name, mixed $value): array
    {
        if (! is_array($value) || array_is_list($value)) {
            $this->fail("params.{$name}", 'Informe um objeto JSON válido.');
        }
        $this->assertDepth($value, 1, "params.{$name}");

        return $value;
    }

    private function base64(string $name, mixed $value): string
    {
        $value = $this->string($name, $value, self::MAX_JSON_BYTES);
        if (base64_decode($value, true) === false) {
            $this->fail("params.{$name}", 'Conteúdo Base64 inválido.');
        }

        return $value;
    }

    /** @return list<mixed> */
    private function arrayValue(string $name, mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            $this->fail("params.{$name}", 'Informe uma lista JSON válida.');
        }
        $this->assertDepth($value, 1, "params.{$name}");

        return $value;
    }

    private function string(string $name, mixed $value, int $max = 500): string
    {
        if (! is_string($value)) {
            $this->fail("params.{$name}", 'Informe um texto válido.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max) {
            $this->fail("params.{$name}", "O texto deve ter entre 1 e {$max} caracteres.");
        }

        return $value;
    }

    /** @param array<mixed> $value */
    private function assertDepth(array $value, int $depth, string $path): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail($path, 'O JSON excede a profundidade máxima permitida.');
        }
        foreach ($value as $child) {
            if (is_array($child)) {
                $this->assertDepth($child, $depth + 1, $path);
            }
        }
    }

    /** @param array<mixed> $value */
    private function assertNoTechnicalKeys(array $value): void
    {
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));
                if (in_array($normalized, self::FORBIDDEN_KEYS, true)) {
                    $this->fail('params.'.$key, 'Campo técnico ou identidade não é aceito pelo navegador.');
                }
            }
            if (is_array($child)) {
                $this->assertNoTechnicalKeys($child);
            }
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
