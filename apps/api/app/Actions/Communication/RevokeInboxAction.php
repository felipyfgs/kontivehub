<?php

namespace App\Actions\Communication;

use App\DTO\Communication\InboxCommandResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Services\Communication\Outbox\OutboxService;
use App\Services\Communication\Pairing\PairingStateStore;
use Illuminate\Support\Facades\DB;

final readonly class RevokeInboxAction
{
    public function __construct(
        private OutboxService $outbox,
        private PairingStateStore $pairing,
    ) {}

    public function handle(CommunicationInbox $inbox): InboxCommandResult
    {
        $result = DB::transaction(function () use ($inbox): InboxCommandResult {
            $locked = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status === InboxStatus::Disconnected
                && $locked->revoked_at !== null) {
                return new InboxCommandResult(
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

            return new InboxCommandResult(
                commandId: $entry->command_id,
                type: $entry->type,
                status: InboxStatus::Disconnected,
            );
        });

        $this->pairing->forget((int) $inbox->id);

        return $result;
    }
}
