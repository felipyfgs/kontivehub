<?php

namespace App\Services\Adn;

use App\Enums\SyncCursorStatus;
use App\Jobs\SyncEstablishmentDistributionJob;
use App\Models\SyncCursor;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class SyncDispatchService
{
    public function claimAndDispatch(
        int $cursorId,
        string $trigger = 'SCHEDULED',
        ?int $triggeredBy = null,
        ?DateTimeInterface $delayUntil = null,
    ): bool {
        $leaseOwner = (string) Str::uuid();

        $claimed = DB::transaction(function () use ($cursorId, $leaseOwner, $delayUntil): bool {
            $cursor = SyncCursor::query()->whereKey($cursorId)->lockForUpdate()->first();

            if ($cursor === null
                || in_array($cursor->status, [SyncCursorStatus::Blocked, SyncCursorStatus::Running], true)
                || $cursor->lock_owner !== null) {
                return false;
            }

            $cursor->status = SyncCursorStatus::Waiting;
            $cursor->locked_at = now();
            $cursor->lock_owner = $leaseOwner;
            $cursor->next_sync_at = $delayUntil ?? now();
            $cursor->save();

            return true;
        });

        if (! $claimed) {
            return false;
        }

        try {
            $pending = SyncEstablishmentDistributionJob::dispatch(
                $cursorId,
                $trigger,
                $triggeredBy,
                $leaseOwner,
            );

            if ($delayUntil !== null) {
                $pending->delay($delayUntil);
            }
        } catch (Throwable $exception) {
            SyncCursor::query()
                ->whereKey($cursorId)
                ->where('lock_owner', $leaseOwner)
                ->update([
                    'status' => SyncCursorStatus::Error->value,
                    'next_sync_at' => now(),
                    'locked_at' => null,
                    'lock_owner' => null,
                    'last_error' => 'Falha interna ao enfileirar sincronização.',
                ]);

            throw $exception;
        }

        return true;
    }
}
