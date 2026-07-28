<?php

namespace App\Http\Requests\Platform;

use App\DTO\Platform\PendingTenantFirstAdminData;
use App\Enums\ActivationMethod;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class UpdatePendingTenantFirstAdminRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'method' => ['required', 'string', Rule::enum(ActivationMethod::class)],
        ];
    }

    public function toDto(): PendingTenantFirstAdminData
    {
        return new PendingTenantFirstAdminData(
            name: (string) $this->validated('name'),
            email: (string) $this->validated('email'),
            method: ActivationMethod::from((string) $this->validated('method')),
        );
    }
}
