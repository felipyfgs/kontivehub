<?php

namespace App\Actions\Communication;

use App\Models\CommunicationCannedResponse;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class DeactivateCannedResponseAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
    ) {}

    public function handle(
        CommunicationCannedResponse $canned,
    ): CommunicationCannedResponse {
        return DB::transaction(function () use ($canned): CommunicationCannedResponse {
            $fresh = CommunicationCannedResponse::query()
                ->whereKey($canned->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! $fresh->is_active) {
                return $fresh;
            }

            $fresh->forceFill([
                'is_active' => false,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();

            $this->events->record((int) $fresh->tenant_id, 'CANNED_RESPONSE_DEACTIVATED', [
                'canned_response_id' => (int) $fresh->id,
                'shortcut' => $fresh->shortcut,
                'lock_version' => (int) $fresh->lock_version,
            ], actorMembershipId: $this->currentTenant->realMembership()?->id);

            return $fresh;
        });
    }
}
