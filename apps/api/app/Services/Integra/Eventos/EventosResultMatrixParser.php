<?php

namespace App\Services\Integra\Eventos;

use App\Enums\MailboxEventItemClassification;
use Carbon\CarbonImmutable;
use JsonException;

final class EventosResultMatrixParser
{
    /**
     * @return list<array{index:int,ni:?string,classification:MailboxEventItemClassification,event_date:?CarbonImmutable,error_code:?string}>
     */
    public function parse(mixed $dados): array
    {
        if (is_string($dados)) {
            try {
                $dados = json_decode($dados, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return [$this->malformed(0, 'EVENTOS_MATRIX_JSON_INVALID')];
            }
        }

        if (is_array($dados) && isset($dados['elementos']) && is_array($dados['elementos'])) {
            $dados = $dados['elementos'];
        }
        if (! is_array($dados) || ! array_is_list($dados)) {
            return [$this->malformed(0, 'EVENTOS_MATRIX_INVALID')];
        }

        $items = [];
        foreach ($dados as $index => $row) {
            if (! is_array($row) || count($row) !== 2 || ! isset($row[0], $row[1]) || ! is_string($row[0]) || ! is_string($row[1])) {
                $items[] = $this->malformed((int) $index, 'EVENTOS_MATRIX_ROW_INVALID');

                continue;
            }

            $ni = strtoupper(preg_replace('/[^0-9A-Z]/i', '', trim($row[0])) ?? '');
            if (! preg_match('/^[0-9A-Z]{14}$/', $ni)) {
                $items[] = $this->malformed((int) $index, 'EVENTOS_MATRIX_NI_INVALID');

                continue;
            }

            $value = strtolower(trim($row[1]));
            if ($value === '') {
                $items[] = $this->item((int) $index, $ni, MailboxEventItemClassification::NoEvent);

                continue;
            }
            if ($value === 'x') {
                $items[] = $this->item((int) $index, $ni, MailboxEventItemClassification::AccessDenied);

                continue;
            }

            try {
                $date = CarbonImmutable::createFromFormat('!ymd', $value, (string) config('serpro.eventos.timezone', 'America/Sao_Paulo'));
            } catch (\Throwable) {
                $date = false;
            }
            if ($date === false || $date->format('ymd') !== $value) {
                $items[] = $this->malformed((int) $index, 'EVENTOS_MATRIX_DATE_INVALID', $ni);

                continue;
            }

            $items[] = $this->item((int) $index, $ni, MailboxEventItemClassification::EventDate, $date);
        }

        return $items;
    }

    /** @return array{index:int,ni:?string,classification:MailboxEventItemClassification,event_date:?CarbonImmutable,error_code:?string} */
    private function item(int $index, string $ni, MailboxEventItemClassification $classification, ?CarbonImmutable $date = null): array
    {
        return compact('index', 'ni', 'classification') + ['event_date' => $date, 'error_code' => null];
    }

    /** @return array{index:int,ni:?string,classification:MailboxEventItemClassification,event_date:?CarbonImmutable,error_code:?string} */
    private function malformed(int $index, string $code, ?string $ni = null): array
    {
        return [
            'index' => $index,
            'ni' => $ni,
            'classification' => MailboxEventItemClassification::Malformed,
            'event_date' => null,
            'error_code' => $code,
        ];
    }
}
