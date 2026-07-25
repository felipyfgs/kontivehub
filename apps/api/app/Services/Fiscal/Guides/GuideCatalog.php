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
     * @return array{system:string,service:string,operation:string,risk:TaxGuideRiskLevel,label:string}
     */
    public function resolve(string $systemCode, string $serviceCode, string $operationCode): array
    {
        $systemCode = strtoupper($systemCode);
        $serviceCode = strtoupper($serviceCode);
        $operationCode = strtoupper($operationCode);

        /** @var list<array{system:string,service:string,operation:string,risk:string,label:string}> $ops */
        $ops = config('tax_guides.operations', []);

        foreach ($ops as $op) {
            if (
                strtoupper($op['system']) === $systemCode
                && strtoupper($op['service']) === $serviceCode
                && strtoupper($op['operation']) === $operationCode
            ) {
                return [
                    'system' => $systemCode,
                    'service' => $serviceCode,
                    'operation' => $operationCode,
                    'risk' => TaxGuideRiskLevel::tryFrom(strtoupper($op['risk'])) ?? TaxGuideRiskLevel::High,
                    'label' => $op['label'],
                ];
            }
        }

        throw GuideException::operationNotCataloged($systemCode, $serviceCode, $operationCode);
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
