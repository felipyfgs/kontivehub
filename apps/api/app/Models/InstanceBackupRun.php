<?php

namespace App\Models;

use Database\Factories\InstanceBackupRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'kind',
    'status',
    'started_at',
    'finished_at',
    'byte_size',
    'manifest_path',
    'checksum',
    'message',
])]
class InstanceBackupRun extends Model
{
    /** @use HasFactory<InstanceBackupRunFactory> */
    use HasFactory;

    public const KIND_FULL = 'full';

    public const KIND_DATABASE = 'database';

    public const KIND_VAULT = 'vault';

    public const KIND_RESTORE_DRILL = 'restore_drill';

    public const STATUS_SUCCESS = 'SUCCESS';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_RUNNING = 'RUNNING';

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'byte_size' => 'integer',
        ];
    }

    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    public function isBackupKind(): bool
    {
        return in_array($this->kind, [self::KIND_FULL, self::KIND_DATABASE, self::KIND_VAULT], true);
    }

    /**
     * @return array{
     *   last_success_at: ?string,
     *   last_full_success_at: ?string,
     *   last_status: ?string,
     *   last_kind: ?string,
     *   last_restore_drill_at: ?string,
     *   last_restore_drill_status: ?string,
     *   stale: bool,
     *   never: bool
     * }
     */
    public static function statusSummary(?int $staleAfterHours = null): array
    {
        $hours = $staleAfterHours ?? (int) config('backup.stale_after_hours', 24);

        // Só conta SUCCESS com manifesto ainda referenciado (artefato não podado).
        $lastAnySuccess = static::query()
            ->whereIn('kind', [self::KIND_FULL, self::KIND_DATABASE, self::KIND_VAULT])
            ->where('status', self::STATUS_SUCCESS)
            ->whereNotNull('finished_at')
            ->whereNotNull('manifest_path')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        // Cobertura "instância protegida" exige kind=full com artefato.
        $lastFullSuccess = static::query()
            ->where('kind', self::KIND_FULL)
            ->where('status', self::STATUS_SUCCESS)
            ->whereNotNull('finished_at')
            ->whereNotNull('manifest_path')
            ->orderByDesc('finished_at')
            ->orderByDesc('id')
            ->first();

        $lastAny = static::query()
            ->whereIn('kind', [self::KIND_FULL, self::KIND_DATABASE, self::KIND_VAULT])
            ->orderByDesc('id')
            ->first();

        $lastDrill = static::query()
            ->where('kind', self::KIND_RESTORE_DRILL)
            ->orderByDesc('id')
            ->first();

        $never = $lastFullSuccess === null;
        $stale = $never
            || ($lastFullSuccess->finished_at !== null
                && $lastFullSuccess->finished_at->lt(now()->subHours($hours)));

        return [
            'last_success_at' => $lastAnySuccess?->finished_at?->toIso8601String(),
            'last_full_success_at' => $lastFullSuccess?->finished_at?->toIso8601String(),
            'last_status' => $lastAny?->status,
            'last_kind' => $lastAny?->kind,
            'last_restore_drill_at' => $lastDrill?->finished_at?->toIso8601String(),
            'last_restore_drill_status' => $lastDrill?->status,
            'stale' => $stale,
            'never' => $never,
        ];
    }
}
