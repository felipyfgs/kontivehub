<?php

namespace App\DTO\Serpro;

use App\Enums\AuthorIdentityType;
use InvalidArgumentException;

/**
 * Contribuinte de lote exclusivo das operações EVENTOSATUALIZACAO.
 *
 * Os tipos 3 (PF) e 4 (PJ) pertencem somente ao envelope destas operações;
 * eles não são identidades individuais e, por isso, não pertencem a
 * FiscalIdentity nem a pedidoDados.dados.
 */
final readonly class EventosBatchContributor
{
    public string $personType;

    /** @var list<string> */
    public array $numbers;

    /**
     * @param  list<string>  $numbers
     */
    private function __construct(
        string $personType,
        array $numbers,
        private bool $isObtain,
    ) {
        $personType = strtoupper(trim($personType));
        if (! in_array($personType, ['PF', 'PJ'], true)) {
            throw new InvalidArgumentException('EVENTOS_PERSON_TYPE_INVALID: use PF ou PJ.');
        }

        if ($isObtain) {
            if ($numbers !== []) {
                throw new InvalidArgumentException('EVENTOS_OBTAIN_BATCH_MUST_BE_EMPTY.');
            }
            $this->personType = $personType;
            $this->numbers = [];

            return;
        }

        if ($numbers === [] || count($numbers) > 1000) {
            throw new InvalidArgumentException('EVENTOS_BATCH_SIZE_INVALID: esperado de 1 a 1000 NIs.');
        }

        $identityType = $personType === 'PF' ? AuthorIdentityType::Cpf : AuthorIdentityType::Cnpj;
        $normalized = array_map(
            static fn (string $number): string => FiscalIdentity::fromNumero($number, $identityType)->numero,
            $numbers,
        );
        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new InvalidArgumentException('EVENTOS_BATCH_DUPLICATE_CONTRIBUTOR.');
        }

        $this->personType = $personType;
        $this->numbers = array_values($normalized);
    }

    /** @param list<string> $numbers */
    public static function forSolicit(string $personType, array $numbers): self
    {
        return new self($personType, $numbers, false);
    }

    public static function forObtain(string $personType): self
    {
        return new self($personType, [], true);
    }

    /** @return array{numero: string, tipo: 3|4} */
    public function toEnvelope(): array
    {
        return [
            'numero' => $this->isObtain ? '' : implode(',', $this->numbers),
            'tipo' => $this->personType === 'PF' ? 3 : 4,
        ];
    }
}
