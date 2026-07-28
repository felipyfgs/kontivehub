<?php

namespace App\Http\Requests\Fiscal\Documents;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;

final class ResolveFiscalDocumentQuarantineRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, TenantPermission::ClientsManage);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'resolution_status' => ['required', 'string', 'in:RESOLVED,DISMISSED'],
            'resolution_code' => ['nullable', 'string', 'max:64'],
            'resolution_notes' => ['nullable', 'string', 'max:500'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function resolutionStatus(): string
    {
        return (string) $this->validated('resolution_status');
    }

    public function resolutionCode(): ?string
    {
        $value = $this->validated('resolution_code') ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function resolutionNotes(): ?string
    {
        $value = $this->validated('resolution_notes') ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Ação não autorizada para o perfil atual.');
    }
}
