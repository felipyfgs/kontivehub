<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\SerproTermDraftData;
use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class GenerateSerproTermDraftRequest extends SerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'vigencia' => ['sometimes', 'date'],
        ];
    }

    public function toDto(): SerproTermDraftData
    {
        return new SerproTermDraftData(
            environment: $this->environment(),
            validUntil: $this->validated('vigencia'),
            actorUserId: $this->actor()->id,
        );
    }
}
