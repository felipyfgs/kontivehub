<?php

namespace App\Services\Communication\Media;

use App\Jobs\Communication\DeleteCommunicationMediaObjectJob;
use App\Models\CommunicationMediaDeletionIntent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/** Durable, idempotent broker for encrypted communication-media removal. */
final class CommunicationMediaDeletionService
{
    private const MAX_ATTEMPTS = 8;

    private const SWEEP_CURSOR_KEY = 'communication:media-deletion:sweep-cursor:v1';

    public function request(string $objectId, ?int $tenantId = null, ?\DateTimeInterface $dueAt = null): void
    {
        if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $objectId) !== 1) {
            throw new \InvalidArgumentException('Object ID de mídia inválido.');
        }
        $intent = CommunicationMediaDeletionIntent::query()->createOrFirst(
            ['object_id' => $objectId],
            ['tenant_id' => $tenantId, 'due_at' => $dueAt ?? now()]
        );
        DB::afterCommit(fn () => $this->dispatchIntent($intent->id));
    }

    public function dispatchDue(int $limit = 100): int
    {
        $intents = CommunicationMediaDeletionIntent::query()
            ->whereNull('deleted_at')
            ->whereNull('failed_at')
            ->where('due_at', '<=', now())
            ->orderBy('id')->limit(max(1, min($limit, 500)))->get();
        foreach ($intents as $intent) {
            $this->dispatchIntent($intent->id);
        }

        return $intents->count();
    }

    /** Queue only durable orphan candidates; references are checked immediately before intent creation. */
    public function sweepOrphans(CommunicationMediaStore $media, int $limit = 100, int $graceMinutes = 1440): int
    {
        $limit = max(1, min($limit, 500));
        $scanLimit = min(500, max(10, $limit * 5));
        $cursor = Cache::get(self::SWEEP_CURSOR_KEY);
        $cursor = is_string($cursor) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $cursor)
            ? $cursor
            : null;
        $created = 0;
        $scanned = 0;
        $lastScanned = null;
        foreach ($media->oldObjectIds(
            now()->subMinutes(max(1, $graceMinutes)),
            $scanLimit,
            $cursor,
        ) as $objectId) {
            $scanned++;
            $lastScanned = $objectId;
            $referenced = DB::table('communication_attachments')->where('object_id', $objectId)->exists()
                || DB::table('communication_inbox_identity_profiles')->where('profile_picture_object_id', $objectId)->exists();
            if (! $referenced) {
                $before = CommunicationMediaDeletionIntent::query()->where('object_id', $objectId)->exists();
                $this->request($objectId);
                $created += $before ? 0 : 1;
                if ($created >= $limit) {
                    break;
                }
            }
        }

        if ($lastScanned !== null && ($scanned >= $scanLimit || $created >= $limit)) {
            Cache::put(self::SWEEP_CURSOR_KEY, $lastScanned, now()->addDays(7));
        } else {
            Cache::forget(self::SWEEP_CURSOR_KEY);
        }

        return $created;
    }

    public function markDeleted(int $intentId): void
    {
        CommunicationMediaDeletionIntent::query()->whereKey($intentId)->whereNull('deleted_at')->update([
            'deleted_at' => now(),
            'failed_at' => null,
            'last_error_code' => null,
        ]);
    }

    public function retry(int $intentId, \Throwable $error): void
    {
        $intent = CommunicationMediaDeletionIntent::query()
            ->whereKey($intentId)
            ->whereNull('deleted_at')
            ->whereNull('failed_at')
            ->first();
        if ($intent === null) {
            return;
        }
        $attempts = (int) $intent->attempts + 1;
        $terminal = $attempts >= self::MAX_ATTEMPTS;
        $delay = min(900, 10 * (2 ** min(6, $attempts - 1)));
        CommunicationMediaDeletionIntent::query()
            ->whereKey($intentId)
            ->whereNull('deleted_at')
            ->whereNull('failed_at')
            ->increment('attempts', 1, [
                'due_at' => $terminal ? $intent->due_at : now()->addSeconds($delay),
                'dispatched_at' => null,
                'last_error_code' => 'MEDIA_DELETE_FAILED',
                'failed_at' => $terminal ? now() : null,
            ]);
    }

    private function dispatchIntent(int $intentId): void
    {
        $now = now();
        $claimed = CommunicationMediaDeletionIntent::query()
            ->whereKey($intentId)
            ->whereNull('deleted_at')
            ->whereNull('failed_at')
            ->where('due_at', '<=', $now)
            ->update(['dispatched_at' => $now, 'due_at' => $now->copy()->addMinutes(10)]);
        if ($claimed !== 1) {
            return;
        }
        $intent = CommunicationMediaDeletionIntent::query()->find($intentId);
        if ($intent !== null) {
            DeleteCommunicationMediaObjectJob::dispatch($intent->object_id, $intent->id);
        }
    }
}
