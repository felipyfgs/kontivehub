<?php

namespace App\Services\Integra\Dctfweb;

use InvalidArgumentException;

/** Decodificador estrito e sanitizador da resposta MIT/LISTAAPURACOES317. */
final class MitListaApuracoesCodec
{
    /**
     * @param  array<string, mixed>  $body
     * @return list<array{period_key:string,id_apuracao:int,situacao:int,data_encerramento:?string,evento_especial:bool,valor_total_apurado:float|int}>
     */
    public function decode(array $body): array
    {
        $rows = $body['Apuracoes'] ?? null;
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new InvalidArgumentException('Resposta MIT 317 inválida: Apuracoes deve ser uma lista.');
        }

        $decoded = [];
        $periods = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: item de apuração malformado.');
            }

            $period = $row['periodoApuracao'] ?? null;
            $id = $row['idApuracao'] ?? null;
            $situacao = $row['situacao'] ?? null;
            $eventoEspecial = $row['eventoEspecial'] ?? null;
            $valor = $row['valorTotalApurado'] ?? null;

            if (! is_string($period) || preg_match('/^(\d{4})(0[1-9]|1[0-2])$/', $period) !== 1) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: periodoApuracao deve estar em AAAAMM.');
            }
            if (! is_int($id) || $id < 0 || ! is_int($situacao) || $situacao < 0) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: idApuracao ou situacao inválidos.');
            }
            if (! is_bool($eventoEspecial) || (! is_int($valor) && ! is_float($valor))) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: valores obrigatórios malformados.');
            }
            if (isset($periods[$period])) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: período duplicado.');
            }

            $dataEncerramento = $row['dataEncerramento'] ?? null;
            if ($dataEncerramento !== null
                && (! is_string($dataEncerramento) || preg_match('/^\d{8}$/', $dataEncerramento) !== 1)) {
                throw new InvalidArgumentException('Resposta MIT 317 inválida: dataEncerramento deve estar em AAAAMMDD.');
            }

            $periods[$period] = true;
            $decoded[] = [
                'period_key' => substr($period, 0, 4).'-'.substr($period, 4, 2),
                'id_apuracao' => $id,
                'situacao' => $situacao,
                'data_encerramento' => $dataEncerramento,
                'evento_especial' => $eventoEspecial,
                'valor_total_apurado' => $valor,
            ];
        }

        return $decoded;
    }
}
