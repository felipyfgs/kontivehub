<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantSerproTermDraftData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class GenerateTenantSerproTermDraftRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'vigencia' => ['sometimes', 'date'],
        ];
    }

    public function toDto(): TenantSerproTermDraftData
    {
        return new TenantSerproTermDraftData(
            environment: $this->environment(),
            validUntil: $this->validated('vigencia'),
            actorUserId: $this->actor()->id,
        );
    }
}
