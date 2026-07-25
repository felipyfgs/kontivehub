<?php

namespace App\Services\Platform;

use App\Enums\OfficeAccessMode;
use App\Models\PlatformPrivilegedAuditEvent;
use App\Models\User;
use App\Support\CurrentOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Grava auditoria interna somente quando CurrentOffice está em platform_privileged.
 * A trilha NÃO deve ser exposta em APIs tenant.
 */
final class PlatformPrivilegedAuditor
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
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
        if (! $this->currentOffice->isPlatformPrivileged()) {
            return;
        }

        $officeId = $this->currentOffice->id();
        if ($officeId === null) {
            return;
        }

        $actor ??= $this->currentOffice->actor() ?? auth()->user();
        if (! $actor instanceof User) {
            return;
        }

        $metadata = array_merge([
            'access_mode' => OfficeAccessMode::PlatformPrivileged->value,
        ], $metadata);

        PlatformPrivilegedAuditEvent::record(
            actorUserId: $actor->id,
            officeId: $officeId,
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
