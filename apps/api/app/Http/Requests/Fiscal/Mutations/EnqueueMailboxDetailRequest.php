<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Auth\Access\AuthorizationException;

final class EnqueueMailboxDetailRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User
            || ! app(TenantAuthorization::class)->allows($actor, TenantPermission::FiscalSyncTrigger)) {
            return false;
        }

        $tenant = app(CurrentTenant::class)->tenant();

        return FeatureFlags::isModuleEnabled('mailbox', (int) $tenant->id);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão para operar o monitoramento da Caixa Postal.');
    }
}
