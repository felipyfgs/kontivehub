<?php

namespace App\Services\Integra\Registrations;

use App\Domain\Cnpj;
use RuntimeException;

/** Decodifica a página oficial de renúncias do PNR Contador. */
final class PnrRenunciationsResponseCodec
{
    /**
     * @return array{rows: list<array{id: int, contributor_cnpj: string, status: string, occurred_at: int|null}>, page: int, last: bool, total: int}
     */
    public function decodeHistory(mixed $dados): array
    {
        if (! is_array($dados) || ! isset($dados['content']) || ! is_array($dados['content'])) {
            throw new RuntimeException('Resposta PNR Contador inválida: dados.content ausente.');
        }

        $rows = [];
        foreach ($dados['content'] as $row) {
            $rows[] = $this->renunciation($row, 'dados.content[]');
        }

        $page = $this->nonNegativeInt($dados['number'] ?? 0, 'dados.number');
        $total = $this->nonNegativeInt($dados['totalElements'] ?? count($rows), 'dados.totalElements');
        if ($total < count($rows)) {
            throw new RuntimeException('Resposta PNR Contador inválida: totalElements menor que content.');
        }

        return [
            'rows' => $rows,
            'page' => $page,
            'last' => $this->boolean($dados['last'] ?? false, 'dados.last'),
            'total' => $total,
        ];
    }

    /**
     * @return array{approved: bool, message: string|null, renunciation: array{id: int, contributor_cnpj: string, status: string, occurred_at: int|null}|null}
     */
    public function decodeStatus(mixed $dados): array
    {
        if (! is_array($dados) || ! array_key_exists('resultado', $dados)) {
            throw new RuntimeException('Resposta PNR Contador inválida: dados.resultado ausente.');
        }

        $message = $dados['mensagemRetorno'] ?? null;
        if ($message !== null && ! is_string($message)) {
            throw new RuntimeException('Resposta PNR Contador inválida: dados.mensagemRetorno deve ser texto.');
        }

        return [
            'approved' => $this->boolean($dados['resultado'], 'dados.resultado'),
            'message' => $message,
            'renunciation' => ($dados['renuncia'] ?? null) === null
                ? null
                : $this->renunciation($dados['renuncia'], 'dados.renuncia'),
        ];
    }

    /** @return array{id: int, contributor_cnpj: string, status: string, occurred_at: int|null} */
    private function renunciation(mixed $row, string $field): array
    {
        if (! is_array($row) || array_is_list($row)) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser objeto.");
        }

        $id = $this->positiveInt($row['id'] ?? null, "{$field}.id");
        $occurredAt = array_key_exists('dataRenuncia', $row) && $row['dataRenuncia'] !== null
            ? $this->positiveInt($row['dataRenuncia'], "{$field}.dataRenuncia")
            : null;

        return [
            'id' => $id,
            // Usado apenas para conferir o contribuinte no serviço de projeção.
            // Nunca entra em resumo, DTO público ou logs.
            'contributor_cnpj' => $this->cnpj($row['cnpjRenunciada'] ?? null, "{$field}.cnpjRenunciada"),
            'status' => 'RENOUNCED',
            'occurred_at' => $occurredAt,
        ];
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $number = $this->nonNegativeInt($value, $field);
        if ($number < 1) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser positivo.");
        }

        return $number;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser inteiro.");
        }

        return (int) $value;
    }

    private function boolean(mixed $value, string $field): bool
    {
        if (! is_bool($value)) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser booleano.");
        }

        return $value;
    }

    private function cnpj(mixed $value, string $field): string
    {
        if (! is_string($value) || ($cnpj = Cnpj::tryParse($value)) === null) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser CNPJ válido.");
        }

        return $cnpj->value();
    }
}
