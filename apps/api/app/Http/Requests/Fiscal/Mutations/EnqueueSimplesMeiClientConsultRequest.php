<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantRole;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

final class EnqueueSimplesMeiClientConsultRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        $role = app(CurrentTenant::class)->role();

        return $role !== null
            && in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ];
    }

    public function clientId(): int
    {
        return (int) $this->validated('client_id');
    }

    public function correlationId(): ?string
    {
        $value = $this->validated('correlation_id');

        return is_string($value) ? $value : null;
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
