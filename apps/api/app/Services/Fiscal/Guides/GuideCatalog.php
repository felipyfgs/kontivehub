<?php

namespace App\Services\Fiscal\Guides;

use App\Enums\TaxGuideRiskLevel;
use App\Services\Fiscal\Guides\Exceptions\GuideException;

/**
 * Catálogo local de operações de guia (alinhado a config + seeds SERPRO).
 */
final class GuideCatalog
{
    /**
     * @return array{operation_key:string,system:string,service:string,operation:string,risk:TaxGuideRiskLevel,label:string}
     */
    public function resolve(string $operationKey): array
    {
        /** @var list<array{operation_key:string,system:string,service:string,operation:string,risk:string,label:string}> $ops */
        $ops = config('tax_guides.operations', []);

        foreach ($ops as $op) {
            if ($op['operation_key'] === $operationKey) {
                return [
                    'operation_key' => $op['operation_key'],
                    'system' => $op['system'],
                    'service' => $op['service'],
                    'operation' => $op['operation'],
                    'risk' => TaxGuideRiskLevel::tryFrom(strtoupper($op['risk'])) ?? TaxGuideRiskLevel::High,
                    'label' => $op['label'],
                ];
            }
        }

        throw GuideException::operationNotCataloged($operationKey);
    }

    public function isEmissionOperation(string $operationCode): bool
    {
        $op = strtoupper($operationCode);

        return str_starts_with($op, 'EMITIR_') || $op === 'GERAR_GUIA';
    }

    public function isPaymentLookupOperation(string $operationCode): bool
    {
        $op = strtoupper($operationCode);

        return str_contains($op, 'PAGAMENTO') || str_contains($op, 'PAYMENT');
    }
}
