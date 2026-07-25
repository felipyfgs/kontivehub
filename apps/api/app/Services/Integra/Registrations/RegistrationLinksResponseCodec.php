<?php

namespace App\Services\Integra\Registrations;

use App\DTO\Serpro\FiscalIdentity;
use App\Enums\AuthorIdentityType;
use InvalidArgumentException;
use RuntimeException;

/** Decodifica exclusivamente o layout oficial do PNR Contador. */
final class RegistrationLinksResponseCodec
{
    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     last_cnpj: string|null,
     *     total_in_page: int,
     *     total_in_database: int|null
     * }
     */
    public function decode(mixed $dados): array
    {
        if (! is_array($dados) || ! array_key_exists('cnpjs', $dados) || ! is_array($dados['cnpjs'])) {
            throw new RuntimeException('Resposta PNR Contador fora do layout oficial: dados.cnpjs ausente.');
        }

        $rows = [];
        foreach ($dados['cnpjs'] as $row) {
            if (! is_array($row) || array_is_list($row)) {
                throw new RuntimeException('Resposta PNR Contador contém vínculo inválido.');
            }

            $cnpj = $this->cnpj($row['cnpj'] ?? null, 'dados.cnpjs[].cnpj');
            $row['cnpj'] = $cnpj;
            $rows[] = $row;
        }

        $totalInPage = $this->nonNegativeInt(
            $dados['totalInThePage'] ?? count($rows),
            'dados.totalInThePage',
        );
        if ($totalInPage !== count($rows)) {
            throw new RuntimeException('Resposta PNR Contador inconsistente: totalInThePage diverge de cnpjs.');
        }

        $totalInDatabase = array_key_exists('totalInTheDatabase', $dados)
            ? $this->nonNegativeInt($dados['totalInTheDatabase'], 'dados.totalInTheDatabase')
            : null;
        if ($totalInDatabase !== null && $totalInDatabase < $totalInPage) {
            throw new RuntimeException('Resposta PNR Contador inconsistente: totalInTheDatabase inválido.');
        }

        $lastCnpj = null;
        if (array_key_exists('lastCnpj', $dados) && $dados['lastCnpj'] !== null && $dados['lastCnpj'] !== '') {
            $lastCnpj = $this->cnpj($dados['lastCnpj'], 'dados.lastCnpj');
        }

        return [
            'rows' => $rows,
            'last_cnpj' => $lastCnpj,
            'total_in_page' => $totalInPage,
            'total_in_database' => $totalInDatabase,
        ];
    }

    private function cnpj(mixed $value, string $field): string
    {
        if (! is_string($value) && ! is_int($value)) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} obrigatório.");
        }

        try {
            return FiscalIdentity::fromNumero((string) $value, AuthorIdentityType::Cnpj)->numero;
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} não é CNPJ completo.", 0, $exception);
        }
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        if (! is_int($value) && ! (is_string($value) && ctype_digit($value))) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser inteiro.");
        }

        $number = (int) $value;
        if ($number < 0) {
            throw new RuntimeException("Resposta PNR Contador inválida: {$field} deve ser não negativo.");
        }

        return $number;
    }
}
