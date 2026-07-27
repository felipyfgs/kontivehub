<?php

namespace App\Services\Platform;

use App\Enums\TenantAccessMode;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Grava auditoria interna somente quando CurrentTenant está em platform_privileged.
 * A trilha NÃO deve ser exposta em APIs tenant.
 */
final class PlatformPrivilegedAuditor
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function recordIfPrivileged(
        string $action,
        string $result = PlatformPrivilegedAuditEvent::RESULT_SUCCESS,
        ?Model $target = null,
        array $metadata = [],
        ?User $actor = null,
    ): void {
        if (! $this->currentTenant->isPlatformPrivileged()) {
            return;
        }

        $tenantId = $this->currentTenant->id();
        if ($tenantId === null) {
            return;
        }

        $actor ??= $this->currentTenant->actor() ?? auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $metadata = array_merge([
            'access_mode' => TenantAccessMode::PlatformPrivileged->value,
        ], $metadata);

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $actor->id,
            tenantId: $tenantId,
            action: $action,
            result: $result,
            targetType: $target !== null ? $target::class : null,
            targetId: $target?->getKey() !== null ? (int) $target->getKey() : null,
            requestId: $this->requestId(),
            metadata: $metadata,
        );
    }

    private function requestId(): string
    {
        $existing = request()?->attributes->get('correlation_id');
        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $id = (string) Str::uuid();
        request()?->attributes->set('correlation_id', $id);

        return $id;
    }
}
