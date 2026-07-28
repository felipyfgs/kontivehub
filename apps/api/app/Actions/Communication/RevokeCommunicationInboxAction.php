<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationInboxCommandResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use Illuminate\Support\Facades\DB;

final readonly class RevokeCommunicationInboxAction
{
    public function __construct(
        private CommunicationOutboxService $outbox,
        private CommunicationPairingStateStore $pairing,
    ) {}

    public function handle(CommunicationInbox $inbox): CommunicationInboxCommandResult
    {
        $result = DB::transaction(function () use ($inbox): CommunicationInboxCommandResult {
            $locked = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === InboxStatus::Disconnected
                && $locked->revoked_at !== null) {
                return new CommunicationInboxCommandResult(
                    commandId: null,
                    type: GatewayCommandType::LogoutSession,
                    status: InboxStatus::Disconnected,
                );
            }

            $entry = $this->outbox->enqueue(
                $locked,
                GatewayCommandType::LogoutSession,
                [],
            );
            $locked->forceFill([
                'status' => InboxStatus::Disconnected,
                'revoked_at' => now(),
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            return new CommunicationInboxCommandResult(
                commandId: $entry->command_id,
                type: $entry->type,
                status: InboxStatus::Disconnected,
            );
        });

        $this->pairing->forget((int) $inbox->id);

        return $result;
    }
}
