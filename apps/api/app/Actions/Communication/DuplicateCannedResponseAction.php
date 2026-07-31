<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CannedResponseDuplicationData;
use App\Models\CommunicationCannedResponse;
use App\Services\Communication\Canned\CannedResponseConflictMapper;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class DuplicateCannedResponseAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
        private CannedResponseConflictMapper $conflicts,
    ) {}

    public function handle(
        CommunicationCannedResponse $source,
        CannedResponseDuplicationData $data,
    ): CommunicationCannedResponse {
        try {
            return DB::transaction(function () use ($source, $data): CommunicationCannedResponse {
                $lockedSource = CommunicationCannedResponse::query()
                    ->whereKey($source->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $membershipId = $this->currentTenant->realMembership()?->id;
                $item = CommunicationCannedResponse::query()->create([
                    'tenant_id' => (int) $lockedSource->tenant_id,
                    'title' => $data->title ?? $lockedSource->title,
                    'shortcut' => $data->shortcut,
                    'body_encrypted' => $lockedSource->body_encrypted,
                    'is_active' => true,
                    'lock_version' => 1,
                    'created_by_membership_id' => $membershipId,
                ]);

                $this->events->record((int) $item->tenant_id, 'CANNED_RESPONSE_DUPLICATED', [
                    'canned_response_id' => (int) $item->id,
                    'source_canned_response_id' => (int) $lockedSource->id,
                    'shortcut' => $item->shortcut,
                    'lock_version' => (int) $item->lock_version,
                ], actorMembershipId: $membershipId);

                return $item;
            });
        } catch (QueryException $error) {
            $this->conflicts->throwIfShortcutConflict($error);

            throw $error;
        }
    }
}
