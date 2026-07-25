<?php

namespace App\Services\Fiscal\Guides;

use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/** Normaliza filtros oficiais e remove identificadores fiscais da resposta PAGTOWEB 7.1. */
final class PagtowebPaymentListCodec
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{business_data:array<string,mixed>,filter_summary:array<string,mixed>,document_numbers:list<string>}
     */
    public function normalizeFilters(array $filters): array
    {
        $allowed = ['intervalo_data_arrecadacao', 'intervalo_valor_total_documento', 'codigo_receita_lista', 'codigo_tipo_documento_lista', 'numero_documento_lista', 'page', 'per_page'];
        if (array_diff(array_keys($filters), $allowed) !== []) {
            throw new InvalidArgumentException('Filtro de pagamentos não permitido.');
        }
        $range = $filters['intervalo_data_arrecadacao'] ?? null;
        $documents = $this->documents($filters['numero_documento_lista'] ?? null);
        if ($range === null && $documents === []) {
            throw new InvalidArgumentException('Informe o intervalo de arrecadação ou os documentos.');
        }
        $page = isset($filters['page']) ? filter_var($filters['page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : 1;
        $perPage = isset($filters['per_page']) ? filter_var($filters['per_page'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]) : 50;
        if ($page === false || $perPage === false) {
            throw new InvalidArgumentException('Paginação de pagamentos inválida.');
        }
        $business = ['tamanhoDaPagina' => $perPage, 'primeiroDaPagina' => ($page - 1) * $perPage];
        $summary = ['page' => $page, 'per_page' => $perPage];
        if ($range !== null) {
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
        if ($documents !== []) {
            $business['numeroDocumentoLista'] = $documents;
            $summary['numero_documento_digests'] = array_map($this->documentDigest(...), $documents);
        }
        if (isset($filters['intervalo_valor_total_documento'])) {
            $amount = $filters['intervalo_valor_total_documento'];
            if (! is_array($amount)) {
                throw new InvalidArgumentException('Intervalo de valor inválido.');
            }
            $min = $amount['valor_inicial'] ?? $amount['valorInicial'] ?? null;
            $max = $amount['valor_final'] ?? $amount['valorFinal'] ?? null;
            if (! is_numeric($min) || ! is_numeric($max) || (float) $min < 0 || (float) $min > (float) $max) {
                throw new InvalidArgumentException('Intervalo de valor inválido.');
            }
            $business['intervaloValorTotalDocumento'] = ['valorInicial' => (float) $min, 'valorFinal' => (float) $max];
            $summary['intervalo_valor_total_documento'] = $business['intervaloValorTotalDocumento'];
        }
        foreach (['codigo_receita_lista' => ['codigoReceitaLista', '/^[0-9]{1,4}$/'], 'codigo_tipo_documento_lista' => ['codigoTipoDocumentoLista', '/^[0-9]{1,2}$/']] as $input => [$output, $pattern]) {
            if (! isset($filters[$input])) {
                continue;
            }
            $values = $filters[$input];
            if (! is_array($values) || $values === [] || count($values) > 100 || array_filter($values, static fn (mixed $value): bool => ! is_string($value) || preg_match($pattern, $value) !== 1)) {
                throw new InvalidArgumentException('Lista de códigos inválida.');
            }
            $business[$output] = array_values(array_unique($values));
            $summary[$input] = $business[$output];
        }

        return [
            'business_data' => $business,
            'filter_summary' => $summary,
            'document_numbers' => $documents,
        ];
    }

    /** @param list<string> $documents */
    public function encryptDocumentNumbers(array $documents): string
    {
        return Crypt::encryptString(json_encode(array_values($documents), JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function decryptDocumentNumbers(string $ciphertext): array
    {
        $decoded = json_decode(Crypt::decryptString($ciphertext), true, flags: JSON_THROW_ON_ERROR);

        return $this->documents($decoded);
    }

    /**
     * Forma canônica do número de documento para match PGDAS ↔ PAGTOWEB:
     * só dígitos, sem zeros à esquerda (exceto "0").
     */
    public function canonicalizeDocumentNumber(string $document): string
    {
        $digits = preg_replace('/\D+/', '', trim($document)) ?? '';
        $canonical = ltrim($digits, '0');

        return $canonical === '' ? '0' : $canonical;
    }

    public function documentDigest(string $document): string
    {
        return hash_hmac(
            'sha256',
            $this->canonicalizeDocumentNumber($document),
            (string) config('app.key'),
        );
    }

    /** @return list<array{document_digest:string,document_masked:string,document_type:?string,revenue_code:?string,revenue_description:?string,paid_on:?string,due_on:?string,total_amount:?float}> */
    public function decodePayments(array|string|null $payload): array
    {
        if (is_array($payload) && array_key_exists('dados', $payload)) {
            $payload = $payload['dados'];
        }
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Resposta PAGTOWEB sem lista válida.');
        }
        $rows = $this->rows($payload);
        $items = [];
        foreach ($rows as $row) {
            $document = $this->string($row, ['numeroDocumento', 'identificador']);
            if ($document === null || mb_strlen($document) > 17) {
                throw new InvalidArgumentException('Pagamento retornado sem documento válido.');
            }
            $items[] = [
                'document_digest' => $this->documentDigest($document),
                'document_masked' => $this->mask($document),
                'document_type' => $this->nestedString($row, ['tipo.descricao', 'tipo.descricaoAbreviada', 'tipo.codigo']),
                'revenue_code' => $this->nestedString($row, ['receitaPrincipal.codigo']),
                'revenue_description' => $this->nestedString($row, ['receitaPrincipal.descricao']),
                'paid_on' => $this->date($this->string($row, ['dataArrecadacao', 'dataPagamento'])),
                'due_on' => $this->date($this->string($row, ['dataVencimento'])),
                'total_amount' => $this->number($row['valorTotal'] ?? $row['valorPago'] ?? null),
            ];
        }

        return $items;
    }

    /** @param array<string,mixed> $payload @return list<array<string,mixed>> */
    private function rows(array $payload): array
    {
        foreach (['pagamentos', 'documentos', 'items', 'lista'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                return array_values(array_filter($payload[$key], 'is_array'));
            }
        }

        return array_is_list($payload) ? array_values(array_filter($payload, 'is_array')) : [];
    }

    /** @param array<string,mixed> $row @param list<string> $keys */
    private function string(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && is_scalar($row[$key]) && trim((string) $row[$key]) !== '') {
                return trim((string) $row[$key]);
            }
        }

        return null;
    }

    /** @param array<string,mixed> $row @param list<string> $paths */
    private function nestedString(array $row, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = $row;
            foreach (explode('.', $path) as $part) {
                if (! is_array($value) || ! array_key_exists($part, $value)) {
                    $value = null;
                    break;
                }
                $value = $value[$part];
            }
            if (is_scalar($value) && trim((string) $value) !== '') {
                return mb_substr(trim((string) $value), 0, 255);
            }
        }

        return null;
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private function date(?string $value): ?string
    {
        return $value !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $value) === 1 ? substr($value, 0, 10) : null;
    }

    private function mask(string $value): string
    {
        $length = mb_strlen($value);

        return str_repeat('•', max(0, $length - 4)).mb_substr($value, -min(4, $length));
    }

    private function validDate(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false;
    }

    /** @return list<string> */
    private function documents(mixed $documents): array
    {
        if ($documents === null) {
            return [];
        }
        if (! is_array($documents) || $documents === [] || count($documents) > 100) {
            throw new InvalidArgumentException('Lista de documentos inválida.');
        }

        $normalized = [];
        foreach ($documents as $document) {
            if (! is_string($document) || preg_match('/^[0-9]{1,17}$/', trim($document)) !== 1) {
                throw new InvalidArgumentException('Lista de documentos inválida.');
            }
            $normalized[] = trim($document);
        }

        return array_values(array_unique($normalized));
    }
}
