<?php

namespace App\DTO\Integra;

use InvalidArgumentException;

/**
 * Filtros oficiais de MIT/LISTAAPURACOES317.
 *
 * Não recebe identidades, office_id, tokens ou parâmetros técnicos: estes são
 * sempre resolvidos pelo executor central a partir do contexto do escritório.
 */
final readonly class MitListaApuracoesRequest
{
    public function __construct(
        public ?int $anoApuracao = null,
        public ?int $mesApuracao = null,
        public ?int $situacaoApuracao = null,
    ) {
        if ($this->anoApuracao !== null && ($this->anoApuracao < 2000 || $this->anoApuracao > 2100)) {
            throw new InvalidArgumentException('anoApuracao deve estar entre 2000 e 2100.');
        }

        if ($this->mesApuracao !== null && ($this->mesApuracao < 1 || $this->mesApuracao > 12)) {
            throw new InvalidArgumentException('mesApuracao deve estar entre 1 e 12.');
        }

        if ($this->mesApuracao !== null && $this->anoApuracao === null) {
            throw new InvalidArgumentException('mesApuracao exige anoApuracao.');
        }

        // O catálogo oficial documenta Number, mas não publica enum de situação.
        // Aceita somente inteiros não negativos, sem inventar estados de negócio.
        if ($this->situacaoApuracao !== null && ($this->situacaoApuracao < 0 || $this->situacaoApuracao > 9999)) {
            throw new InvalidArgumentException('situacaoApuracao deve ser um inteiro não negativo válido.');
        }
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $allowed = ['anoApuracao', 'mesApuracao', 'situacaoApuracao'];
        $unexpected = array_diff(array_keys($input), $allowed);
        if ($unexpected !== []) {
            throw new InvalidArgumentException('Filtro MIT não reconhecido.');
        }

        foreach ($allowed as $field) {
            if (isset($input[$field]) && ! is_int($input[$field])) {
                throw new InvalidArgumentException("{$field} deve ser inteiro.");
            }
        }

        return new self(
            anoApuracao: $input['anoApuracao'] ?? null,
            mesApuracao: $input['mesApuracao'] ?? null,
            situacaoApuracao: $input['situacaoApuracao'] ?? null,
        );
    }

    /** @return array<string, int> */
    public function toPayload(): array
    {
        return array_filter([
            'anoApuracao' => $this->anoApuracao,
            'mesApuracao' => $this->mesApuracao,
            'situacaoApuracao' => $this->situacaoApuracao,
        ], static fn (?int $value): bool => $value !== null);
    }
}
