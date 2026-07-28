<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Enums\TenantPermission;

final class PreviewMailboxDetailRequest extends MailboxReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function requiredPermission(): TenantPermission
    {
        return TenantPermission::FiscalSyncTrigger;
    }

    protected function permissionDeniedMessage(): string
    {
        return 'Sem permissão para operar o monitoramento da Caixa Postal.';
    }
}
