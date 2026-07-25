<?php

namespace App\Services\Serpro;

use App\Domain\BrazilianTaxId;
use App\Enums\SerproEligibilityCode;
use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateKind;
use App\Models\SerproExternalGate;

/**
 * Gate OFFICIAL_CLARIFICATION_REQUIRED para campos numéricos conflitantes
 * do Termo/Eventos quando o CNPJ alfanumérico ainda não tem confirmação oficial.
 */
final class OfficialClarificationGate
{
    /**
     * Contextos documentais ainda restritos a 14 dígitos numéricos na doc oficial.
     */
    public const CONTEXT_TERMO_XML = 'TERMO_XML';

    public const CONTEXT_EVENTOS_PAYLOAD = 'EVENTOS_PAYLOAD';

    public function cnpjAlphanumericGateOpen(): bool
    {
        $gate = SerproExternalGate::query()
            ->where('kind', SerproExternalGateKind::CnpjAlphanumericSerialization->value)
            ->first();

        if ($gate === null) {
            // Sem evidência de resolução → fail-closed
            return true;
        }

        return $gate->blocksProduction();
    }

    /**
     * Avalia se um valor de CNPJ pode ser emitido em contexto Termo/Eventos em produção.
     *
     * @return array{allowed: bool, code: ?SerproEligibilityCode, reason: ?string}
     */
    public function evaluateCnpjField(
        string $rawCnpj,
        string $context,
        SerproEnvironment $environment,
    ): array {
        $id = BrazilianTaxId::tryParse($rawCnpj);
        if ($id === null || ! $id->isCnpj()) {
            return [
                'allowed' => false,
                'code' => SerproEligibilityCode::OfficialClarificationRequired,
                'reason' => 'CNPJ inválido para contexto '.$context,
            ];
        }

        $isAlpha = (bool) preg_match('/[A-Z]/', $id->value());
        if (! $isAlpha) {
            return [
                'allowed' => true,
                'code' => null,
                'reason' => null,
            ];
        }

        // Alfanumérico: fora de produção/homologação real pode seguir (trial/tests)
        if ($environment === SerproEnvironment::Trial) {
            return [
                'allowed' => true,
                'code' => null,
                'reason' => null,
            ];
        }

        if (in_array($context, [self::CONTEXT_TERMO_XML, self::CONTEXT_EVENTOS_PAYLOAD], true)
            && $this->cnpjAlphanumericGateOpen()
        ) {
            return [
                'allowed' => false,
                'code' => SerproEligibilityCode::OfficialClarificationRequired,
                'reason' => 'CNPJ alfanumérico em campo Termo/Eventos aguarda esclarecimento oficial SERPRO/RFB.',
            ];
        }

        return [
            'allowed' => true,
            'code' => null,
            'reason' => null,
        ];
    }

    public function assertCnpjFieldAllowed(
        string $rawCnpj,
        string $context,
        SerproEnvironment $environment,
    ): void {
        $result = $this->evaluateCnpjField($rawCnpj, $context, $environment);
        if (! $result['allowed']) {
            throw new \RuntimeException(
                $result['reason'] ?? SerproEligibilityCode::OfficialClarificationRequired->value
            );
        }
    }
}
