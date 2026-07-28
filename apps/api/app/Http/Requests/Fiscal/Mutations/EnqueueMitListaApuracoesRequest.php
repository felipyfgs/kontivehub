<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

final class EnqueueMitListaApuracoesRequest extends AuthenticatedRequest
{
    use RejectsClientTenantId;

    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, TenantPermission::FiscalSyncTrigger);
    }

    protected function prepareForValidation(): void
    {
        $this->rejectClientTenantIdIfSupplied();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'anoApuracao' => ['sometimes', 'nullable', 'integer', 'between:2000,2100'],
            'mesApuracao' => ['sometimes', 'nullable', 'integer', 'between:1,12'],
            'situacaoApuracao' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:9999'],
            'correlation_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function listaData(): array
    {
        return $this->validated();
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
