<?php

namespace App\Http\Requests\Tenant;

use App\Enums\SerproEnvironment;
use Illuminate\Validation\Rule;

final class SignSerproTermRequest extends SerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
            'consent' => ['required', 'accepted'],
        ];
    }
}
