<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationInboxCommandResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class DeleteCommunicationInboxAction
{
    public function __construct(
        private CommunicationOutboxService $outbox,
        private CommunicationPairingStateStore $pairing,
        private CommunicationEventRecorder $events,
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(CommunicationInbox $inbox): CommunicationInboxCommandResult
    {
        $tenantId = (int) $inbox->tenant_id;
        $inboxId = (int) $inbox->id;
        $actorMembershipId = $this->currentTenant->realMembership()?->id;

        $result = DB::transaction(function () use (
            $inbox,
            $tenantId,
            $inboxId,
            $actorMembershipId,
        ): CommunicationInboxCommandResult {
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

            return new CommunicationInboxCommandResult(
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
