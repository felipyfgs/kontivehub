<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;

class ViewFiscalMonitoringSurfaceRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Perfil não resolvido.');
    }
}
