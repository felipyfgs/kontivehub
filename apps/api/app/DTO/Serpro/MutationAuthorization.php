<?php

namespace App\DTO\Serpro;

use App\Enums\FiscalMutationStatus;
use App\Models\FiscalMutationOperation;

/**
 * Autorização tipada de mutação fiscal (Emitir/Declarar/etc.).
 *
 * Só pode ser emitida a partir de uma operação persistida que já passou por
 * preflight, confirmação e revalidação de policy. O padrão permanece fechado.
 */
final class MutationAuthorization
{
    private function __construct(
        public readonly bool $approved,
        public readonly string $reasonCode,
        public readonly ?string $approverRef = null,
        public readonly ?string $policyVersion = null,
        public readonly ?string $approvedAtIso = null,
        public readonly ?string $allowedOperationKey = null,
        public readonly ?int $mutationOperationId = null,
        public readonly ?string $payloadDigest = null,
    ) {}

    /**
     * Sem autorização de mutação (padrão fail-closed).
     */
    public static function none(): self
    {
        return new self(
            approved: false,
            reasonCode: 'MUTATION_DISABLED',
        );
    }

    public static function fromPersistedOperation(
        FiscalMutationOperation $operation,
        string $operationKey,
    ): self {
        $eligibility = $operation->eligibility_snapshot ?? [];
        $persistedKey = trim((string) $operation->provider_operation_key);
        $digest = trim((string) $operation->request_payload_digest);
        $eligible = ($eligibility['allowed'] ?? false) === true;

        if ($operation->status !== FiscalMutationStatus::Sent
            || ! $operation->confirmed_by_user
            || $operation->confirmed_at === null
            || ! $eligible
            || $operation->requested_by === null
            || $persistedKey === ''
            || ! hash_equals($persistedKey, $operationKey)
            || preg_match('/^[a-f0-9]{64}$/', $digest) !== 1
        ) {
            return new self(false, 'PERSISTED_AUTHORIZATION_INVALID');
        }

        return new self(
            approved: true,
            reasonCode: 'PERSISTED_PREFLIGHT_REVALIDATED',
            approverRef: 'user:'.$operation->requested_by,
            policyVersion: 'fiscal-mutation-v1',
            approvedAtIso: $operation->confirmed_at->toIso8601String(),
            allowedOperationKey: $persistedKey,
            mutationOperationId: (int) $operation->id,
            payloadDigest: $digest,
        );
    }

    /**
     * Leituras não exigem aprovação. Mutações exigem vínculo exato com a chave
     * oficial persistida; uma autorização não pode ser reutilizada em outra ação.
     */
    public function allowsMutatingOperation(string $operationKey, bool $isMutating): bool
    {
        if (! $isMutating) {
            return true;
        }

        return $this->approved
            && $this->reasonCode === 'PERSISTED_PREFLIGHT_REVALIDATED'
            && $this->approverRef !== null
            && $this->approverRef !== ''
            && $this->policyVersion === 'fiscal-mutation-v1'
            && $this->approvedAtIso !== null
            && $this->mutationOperationId !== null
            && $this->payloadDigest !== null
            && $this->allowedOperationKey !== null
            && hash_equals($this->allowedOperationKey, $operationKey);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'approved' => $this->approved,
            'reason_code' => $this->reasonCode,
            'has_approver' => $this->approverRef !== null && $this->approverRef !== '',
            'policy_version' => $this->policyVersion,
            'approved_at' => $this->approvedAtIso,
            'has_operation_binding' => $this->allowedOperationKey !== null,
            'mutation_operation_id' => $this->mutationOperationId,
        ];
    }
}
