<?php

namespace App\Http\Requests\Imports;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;

abstract class DocumentImportBatchReadRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(
            EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED,
        ) || $this->request->has('tenant_id') || $this->query->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }
}
