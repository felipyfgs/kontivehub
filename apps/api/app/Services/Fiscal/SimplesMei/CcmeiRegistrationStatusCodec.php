<?php

namespace App\Services\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use InvalidArgumentException;

/** Allowlist fail-closed de CCMEISITCADASTRAL123. */
final class CcmeiRegistrationStatusCodec
{
    /** @param array<string, mixed> $payload @return array{status:string,enquadrado_mei:bool,situation:FiscalSituation,count:int} */
    public function decode(array $payload): array
    {
        $items = $payload['data'] ?? $payload['dados'] ?? $payload;
        if (! is_array($items) || ! array_is_list($items) || $items === []) {
            throw new InvalidArgumentException('Resposta de situação cadastral CCMEI inválida.');
        }

        $statuses = [];
        $enquadramentos = [];
        foreach ($items as $item) {
            if (! is_array($item) || ! is_string($item['situacao'] ?? null) || ! is_bool($item['enquadradoMei'] ?? null)) {
                throw new InvalidArgumentException('Resposta de situação cadastral CCMEI inválida.');
            }
            $status = mb_strtoupper(trim($item['situacao']));
            if ($status === '') {
                throw new InvalidArgumentException('Resposta de situação cadastral CCMEI inválida.');
            }
            $statuses[] = $status;
            $enquadramentos[] = $item['enquadradoMei'];
        }

        $uniqueStatuses = array_values(array_unique($statuses));
        $allMei = ! in_array(false, $enquadramentos, true);

        return [
            'status' => count($uniqueStatuses) === 1 ? $uniqueStatuses[0] : 'DIVERGENTE',
            'enquadrado_mei' => $allMei,
            'situation' => count($uniqueStatuses) === 1 && in_array($uniqueStatuses[0], ['ATIVA', 'ATIVO'], true) && $allMei
                ? FiscalSituation::UpToDate
                : FiscalSituation::Attention,
            'count' => count($items),
        ];
    }
}
