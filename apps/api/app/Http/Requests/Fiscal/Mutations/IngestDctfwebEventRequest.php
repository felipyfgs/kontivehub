<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantRole;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;

final class IngestDctfwebEventRequest extends AuthenticatedRequest
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
            'period_key' => ['required', 'string', 'max:20'],
            'event_type' => ['sometimes', 'string', 'max:80'],
            'external_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'payload_digest' => ['sometimes', 'nullable', 'string', 'max:64'],
            'enqueue' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->validated();
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
