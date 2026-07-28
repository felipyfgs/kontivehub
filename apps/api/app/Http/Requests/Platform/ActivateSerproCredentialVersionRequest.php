<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\CredentialVersionActivationData;
use App\Http\Requests\AuthenticatedRequest;

final class ActivateSerproCredentialVersionRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'skip_oauth' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'approval_id' => ['sometimes', 'integer', 'exists:serpro_rollout_approvals,id'],
            'serpro_contract_id' => ['sometimes', 'integer', 'exists:serpro_contracts,id'],
        ];
    }

    public function toDto(): CredentialVersionActivationData
    {
        $validated = $this->validated();

        return new CredentialVersionActivationData(
            skipOauth: (bool) ($validated['skip_oauth'] ?? false),
            reason: isset($validated['reason']) ? (string) $validated['reason'] : null,
            approvalId: isset($validated['approval_id']) ? (int) $validated['approval_id'] : null,
            contractId: isset($validated['serpro_contract_id'])
                ? (int) $validated['serpro_contract_id']
                : null,
        );
    }
}
