<?php

namespace App\Http\Requests\Sefaz;

use App\Enums\TenantPermission;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Auth\Access\AuthorizationException;

final class RepairKnownCteNsuRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalSyncTrigger,
            );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'cursor_id' => ['required', 'integer'],
            'nsu' => ['required', 'integer', 'min:1'],
        ];
    }

    public function cursorId(): int
    {
        return (int) $this->validated('cursor_id');
    }

    public function nsu(): int
    {
        return (int) $this->validated('nsu');
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Sem permissão de sincronização.');
    }
}
