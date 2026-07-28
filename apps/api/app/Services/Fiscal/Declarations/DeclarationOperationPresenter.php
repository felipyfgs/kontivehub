<?php

namespace App\Services\Fiscal\Declarations;

use App\Models\FiscalMutationOperation;

/**
 * Projeções públicas action-id-only da central de declarações.
 */
final class DeclarationOperationPresenter
{
    /** @return array<string, mixed> */
    public function mutation(FiscalMutationOperation $operation, string $action): array
    {
        $payload = $operation->toPublicArray();
        foreach ([
            'tenant_id',
            'solution_code',
            'service_code',
            'operation_code',
            'module_key',
            'pre_operation_snapshot',
        ] as $field) {
            unset($payload[$field]);
        }
        if (is_array($payload['eligibility'] ?? null)) {
            $payload['eligibility'] = $this->policy($payload['eligibility']);
        }
        $payload['action_id'] = $action;

        return $this->withoutTechnicalFields($payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function preflight(array $payload, string $action): array
    {
        $allowed = array_intersect_key($payload, array_flip([
            'eligible',
            'replayed',
            'confirmation_required',
            'confirmation_phrase',
            'effect',
            'contribuinte',
            'competence',
            'eligibility',
            'cost_estimate',
            'estimated_cost_micros',
            'preflight_token',
            'preflight_expires_at',
            'idempotency_key',
            'correlation_id',
            'mutation_operation_id',
            'status',
            'denial_code',
            'denial_message',
            'codes',
        ]));
        if (is_array($allowed['eligibility'] ?? null)) {
            $allowed['eligibility'] = $this->policy($allowed['eligibility']);
        }
        $allowed['action_id'] = $action;

        return $this->withoutTechnicalFields($allowed);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function readPayload(array $payload, string $action): array
    {
        $payload['action_id'] = $action;

        return $this->withoutTechnicalFields($payload);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return array<string, mixed>
     */
    private function policy(array $policy): array
    {
        return array_intersect_key($policy, array_flip([
            'allowed',
            'codes',
            'primary_code',
            'messages',
            'confirmation_required',
            'password_confirmation_required',
        ]));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withoutTechnicalFields(array $payload): array
    {
        $blocked = [
            'tenant_id',
            'system_code',
            'solution_code',
            'service_code',
            'operation_code',
            'operation_key',
            'id_sistema',
            'id_servico',
            'versao_sistema',
            'contractor_cnpj',
            'author_identity',
            'contributor_cnpj',
        ];
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value)
                ? $this->withoutTechnicalFields($value)
                : $value;
        }

        return $clean;
    }
}
