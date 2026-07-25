<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\SimplesMei\RegimeCalendarOptionDto;
use InvalidArgumentException;

/** Decodifica estritamente o SCAPED JSON de CONSULTARANOSCALENDARIOS102. */
final class RegimeCalendarOptionsCodec
{
    /**
     * @param  array<array-key, mixed>  $payload
     * @return list<array{calendar_year:int,regime_apuracao:string}>
     */
    public function decode(array $payload): array
    {
        $rows = array_is_list($payload) ? $payload : ($payload['dados'] ?? $payload['itens'] ?? null);
        if (is_string($rows)) {
            $rows = json_decode($rows, true);
        }
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('Resposta Regime 102 inválida: dados deve ser uma lista JSON.');
        }

        $items = [];
        $years = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Resposta Regime 102 inválida: item da lista malformado.');
            }

            $item = RegimeCalendarOptionDto::fromOfficialRow($row)->toArray();
            if (isset($years[$item['calendar_year']])) {
                throw new InvalidArgumentException('Resposta Regime 102 inválida: anoCalendario duplicado.');
            }
            $years[$item['calendar_year']] = true;
            $items[] = $item;
        }

        return $items;
    }
}
