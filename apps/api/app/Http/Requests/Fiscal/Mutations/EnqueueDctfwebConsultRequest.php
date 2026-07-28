<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

final class EnqueueDctfwebConsultRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, TenantPermission::FiscalSyncTrigger);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function consultData(): array
    {
        return $this->validated();
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
