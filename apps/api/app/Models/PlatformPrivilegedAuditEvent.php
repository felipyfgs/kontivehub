<?php

namespace App\Models;

use App\Support\LogSanitizer;
use Database\Factories\PlatformPrivilegedAuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Evento append-only de auditoria interna do acesso privilegiado PLATFORM_ADMIN.
 *
 * Trilha exclusiva da plataforma: não expor em APIs/exportações/telas do escritório.
 * Sem updated_at; update e delete são bloqueados no model.
 */
#[Fillable([
    'actor_user_id',
    'office_id',
    'action',
    'target_type',
    'target_id',
    'result',
    'request_id',
    'metadata',
    'created_at',
])]
class PlatformPrivilegedAuditEvent extends Model
{
    /** @use HasFactory<PlatformPrivilegedAuditEventFactory> */
    use HasFactory;

    public const RESULT_SUCCESS = 'SUCCESS';

    public const RESULT_DENIED = 'DENIED';

    public const RESULT_FAILURE = 'FAILURE';

    public const ACTION_SELECT_OFFICE = 'platform.privileged.select_office';

    public const ACTION_CLEAR_OFFICE = 'platform.privileged.clear_office';

    public const ACTION_READ = 'platform.privileged.read';

    public const ACTION_MUTATE = 'platform.privileged.mutate';

    public $timestamps = false;

    protected $table = 'platform_privileged_audit_events';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'target_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            if (is_array($event->metadata)) {
                $event->metadata = LogSanitizer::redact($event->metadata);
            }

            if ($event->created_at === null) {
                $event->created_at = now();
            }
        });

        static::updating(function (): never {
            throw new LogicException(
                'platform_privileged_audit_events é append-only (update proibido).'
            );
        });

        static::deleting(function (): never {
            throw new LogicException(
                'platform_privileged_audit_events é append-only (delete proibido).'
            );
        });
    }

    /**
     * Grava evento com metadados sanitizados (sem segredos).
     *
     * @param  array<string, mixed>  $metadata
     */
    public static function record(
        int $actorUserId,
        int $officeId,
        string $action,
        string $result = self::RESULT_SUCCESS,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $requestId = null,
        array $metadata = [],
    ): self {
        return static::query()->create([
            'actor_user_id' => $actorUserId,
            'office_id' => $officeId,
            'action' => $action,
            'result' => $result,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'request_id' => $requestId,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }
}
