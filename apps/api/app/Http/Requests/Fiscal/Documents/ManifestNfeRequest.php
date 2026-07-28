<?php

namespace App\Http\Requests\Fiscal\Documents;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;

final class ManifestNfeRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows($actor, TenantPermission::FiscalNfeManifest);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string'],
            'justification' => ['nullable', 'string', 'max:255'],
            'purpose' => ['nullable', 'string', 'in:UNLOCK_XML,FISCAL'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function manifestationType(): string
    {
        return (string) $this->validated('type');
    }

    public function justification(): ?string
    {
        $value = $this->validated('justification') ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function purpose(): string
    {
        return (string) ($this->validated('purpose') ?? 'UNLOCK_XML');
    }

    protected function failedAuthorization(): void
    {
        abort(403, 'Ação não autorizada para o perfil atual.');
    }
}
