<?php

namespace App\Actions\Communication;

use App\DTO\Communication\InboxCommandResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Media\MediaDeletionService;
use App\Services\Communication\Outbox\OutboxService;
use App\Services\Communication\Pairing\PairingStateStore;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class DeleteInboxAction
{
    public function __construct(
        private OutboxService $outbox,
        private PairingStateStore $pairing,
        private EventRecorder $events,
        private MediaDeletionService $mediaDeletions,
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(CommunicationInbox $inbox): InboxCommandResult
    {
        $tenantId = (int) $inbox->tenant_id;
        $inboxId = (int) $inbox->id;
        $actorMembershipId = $this->currentTenant->realMembership()?->id;

        $result = DB::transaction(function () use (
            $inbox,
            $tenantId,
            $inboxId,
            $actorMembershipId,
        ): InboxCommandResult {
            $locked = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            $entry = $this->outbox->enqueue(
                $locked,
                GatewayCommandType::LogoutSession,
                [],
            );
            $wasDefault = (bool) $locked->is_default;

            CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $locked->tenant_id)
                ->where('inbox_id', $locked->id)
                ->update(['inbox_id' => null]);

            CommunicationInboxIdentityProfile::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->whereNotNull('profile_picture_object_id')
                ->lockForUpdate()
                ->pluck('profile_picture_object_id')
                ->filter(static fn (mixed $objectId): bool => is_string($objectId) && $objectId !== '')
                ->each(fn (string $objectId) => $this->mediaDeletions->request($objectId, $tenantId));

            $locked->delete();

            if ($wasDefault) {
                $replacement = CommunicationInbox::query()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($replacement !== null) {
                    $replacement->forceFill([
                        'is_default' => true,
                        'lock_version' => (int) $replacement->lock_version + 1,
                    ])->save();
                }
            }

            $this->events->record($tenantId, 'INBOX_DELETED', [
                'inbox_id' => $inboxId,
                'history_preserved' => false,
            ], actorMembershipId: $actorMembershipId);

            return new InboxCommandResult(
                commandId: $entry->command_id,
                type: $entry->type,
                status: InboxStatus::Disconnected,
                deleted: true,
            );
        });

        $this->pairing->forget($inboxId);

        return $result;
    }
}
