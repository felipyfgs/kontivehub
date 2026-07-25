<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;

/** Codec sanitizado de DEFIS / CONSDECLARACAO142. */
final class DefisDeclarationsCodec
{
    /** @param array<string, mixed> $body @return list<array{calendar_year:int,type:string,transmitted_at:?string}> */
    public function decode(array $body): array
    {
        return array_map(static fn (array $item): array => [
            'calendar_year' => $item['calendar_year'],
            'type' => $item['type'],
            'transmitted_at' => null,
        ], $this->decodeWithReferences($body));
    }

    /** @return list<array{calendar_year:int,type:string,transmitted_at:?string,id_defis:?string}> */
    public function decodeWithReferences(array $body): array
    {
        $root = $body['dados'] ?? $body['data'] ?? $body;
        if (is_string($root)) {
            $root = json_decode($root, true);
        }
        $items = is_array($root) && array_is_list($root)
            ? $root
            : (is_array($root['declaracoes'] ?? null) ? $root['declaracoes'] : null);
        if (! is_array($items)) {
            throw new InvalidArgumentException('Resposta DEFIS 142 inválida.');
        }

        $result = [];
        foreach ($items as $item) {
            if (! is_array($item) || preg_match('/^\d{4}$/', (string) ($item['anoCalendario'] ?? '')) !== 1) {
                throw new InvalidArgumentException('Declaração DEFIS 142 inválida.');
            }
            $year = (int) $item['anoCalendario'];
            if ($year < 2000 || $year > 2100 || ! in_array((string) ($item['tipo'] ?? ''), ['1', '2', '3', '4'], true)) {
                throw new InvalidArgumentException('Declaração DEFIS 142 inválida.');
            }
            $idDefis = $item['idDefis'] ?? null;
            if ($idDefis !== null && ((! is_int($idDefis) && ! is_string($idDefis)) || preg_match('/^\d{1,32}$/', (string) $idDefis) !== 1)) {
                $idDefis = null;
            }
            $result[] = ['calendar_year' => $year, 'type' => (string) $item['tipo'], 'transmitted_at' => null, 'id_defis' => $idDefis !== null ? (string) $idDefis : null];
        }

        return $result;
    }
}
