<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\ActivationMethodData;
use App\Enums\ActivationMethod;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class RegenerateTenantActivationRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function toDto(): ActivationMethodData
    {
        return new ActivationMethodData(
            method: ActivationMethod::from((string) $this->validated('method')),
        );
    }
}
