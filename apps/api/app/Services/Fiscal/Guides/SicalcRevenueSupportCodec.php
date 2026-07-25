<?php

namespace App\Services\Fiscal\Guides;

use InvalidArgumentException;

/** Decodifica e reduz o retorno 5.2 ao contrato de apoio seguro para a UI. */
final class SicalcRevenueSupportCodec
{
    private const FLAG_FIELDS = [
        'codigoReceita', 'codigoReceitaExtensao', 'cota', 'dataConsolidacao', 'dataPA',
        'referencia', 'tipoPA', 'valorImposto', 'vencimento', 'cno', 'cnpjPrestador',
        'dataAlienacao', 'ganhoCapital', 'municipio', 'observacao', 'uf', 'valorJuros', 'valorMulta',
    ];

    private const INFO_FIELDS = [
        'calculado', 'codigoBarras', 'codigoReceitaExtensao', 'criacao', 'descricaoReceitaExtensao',
        'descricaoReferencia', 'exigeMatriz', 'manual', 'pf', 'pj', 'tipoPeriodoApuracao', 'vedaValor',
    ];

    /**
     * @param  array<string, mixed>|string|null  $payload
     * @return array{revenue_code:string,description:string,extensions:list<array<string, array<string, bool|string>>>}
     */
    public function decode(array|string|null $payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Resposta SICALC sem dados de receita válidos.');
        }

        $root = is_array($payload['receita'] ?? null) ? $payload['receita'] : $payload;
        $code = trim((string) ($root['codigoReceita'] ?? ''));
        $description = trim((string) ($root['descricaoReceita'] ?? ''));
        if ($code === '' || ! preg_match('/^[0-9]{1,16}$/', $code) || $description === '') {
            throw new InvalidArgumentException('Resposta SICALC sem código ou descrição de receita válidos.');
        }

        $rawExtensions = $root['extensoes'] ?? null;
        if (! is_array($rawExtensions)) {
            throw new InvalidArgumentException('Resposta SICALC sem extensões de receita.');
        }
        $extensions = [];
        foreach ($rawExtensions as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $extension = [
                'obrigatorios' => $this->flags($raw['obrigatorios'] ?? []),
                'opcionais' => $this->flags($raw['opcionais'] ?? []),
                'informacoes' => $this->information($raw['informacoes'] ?? []),
            ];
            if ($extension['obrigatorios'] !== [] || $extension['opcionais'] !== [] || $extension['informacoes'] !== []) {
                $extensions[] = $extension;
            }
        }
        if ($extensions === []) {
            throw new InvalidArgumentException('Resposta SICALC sem extensão utilizável de receita.');
        }

        return ['revenue_code' => $code, 'description' => mb_substr($description, 0, 255), 'extensions' => $extensions];
    }

    /** @return array<string, bool> */
    private function flags(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach (self::FLAG_FIELDS as $field) {
            if (array_key_exists($field, $values) && is_bool($values[$field])) {
                $out[$field] = $values[$field];
            }
        }

        return $out;
    }

    /** @return array<string, bool|string> */
    private function information(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }
        $out = [];
        foreach (self::INFO_FIELDS as $field) {
            $value = $values[$field] ?? null;
            if (is_bool($value)) {
                $out[$field] = $value;
            } elseif (is_scalar($value)) {
                $text = trim((string) $value);
                if ($text !== '') {
                    $out[$field] = mb_substr($text, 0, 255);
                }
            }
        }

        return $out;
    }
}
