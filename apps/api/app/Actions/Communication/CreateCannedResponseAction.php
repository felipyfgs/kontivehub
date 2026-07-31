<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CannedResponseMutationData;
use App\Models\CommunicationCannedResponse;
use App\Services\Communication\Canned\CannedResponseConflictMapper;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class CreateCannedResponseAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
        private CannedResponseConflictMapper $conflicts,
    ) {}

    public function handle(
        CannedResponseMutationData $data,
    ): CommunicationCannedResponse {
        try {
            return DB::transaction(function () use ($data): CommunicationCannedResponse {
                $tenantId = (int) $this->currentTenant->tenant()->id;
                $membershipId = $this->currentTenant->realMembership()?->id;
                $item = CommunicationCannedResponse::query()->create([
                    'tenant_id' => $tenantId,
                    'title' => $data->title,
                    'shortcut' => $data->shortcut,
                    'body_encrypted' => $data->body,
                    'is_active' => $data->isActive ?? true,
                    'lock_version' => 1,
                    'created_by_membership_id' => $membershipId,
                ]);

                $this->events->record($tenantId, 'CANNED_RESPONSE_CREATED', [
                    'canned_response_id' => (int) $item->id,
                    'shortcut' => $item->shortcut,
                    'lock_version' => (int) $item->lock_version,
                    'is_active' => (bool) $item->is_active,
                ], actorMembershipId: $membershipId);

                return $item;
            });
        } catch (QueryException $error) {
            $this->conflicts->throwIfShortcutConflict($error);

            throw $error;
        }
    }
}
