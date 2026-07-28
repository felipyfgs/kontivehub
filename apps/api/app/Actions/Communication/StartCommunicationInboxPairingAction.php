<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationInboxPairingResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class StartCommunicationInboxPairingAction
{
    public function __construct(
        private CommunicationAvailability $availability,
        private CommunicationPairingStateStore $pairing,
        private CommunicationOutboxService $outbox,
    ) {}

    public function handle(CommunicationInbox $inbox): CommunicationInboxPairingResult
    {
        $this->availability->assertEnabled($inbox);
        if ($inbox->status === InboxStatus::Connected) {
            $this->pairing->forget((int) $inbox->id);

            return new CommunicationInboxPairingResult([
                'command_id' => null,
                'type' => GatewayCommandType::ConnectSession->value,
                'event' => 'success',
                'status' => InboxStatus::Connected->value,
                'commands' => [],
            ]);
        }

        $state = [
            'event' => 'pending',
            'status' => InboxStatus::Connecting->value,
            'expires_at' => now()->addMinutes(2)->toIso8601String(),
            'commands' => [],
        ];
        if (! $this->pairing->reserve((int) $inbox->id, $state)) {
            return new CommunicationInboxPairingResult(
                $this->pairing->get((int) $inbox->id) ?? $state,
            );
        }

        try {
            $entry = DB::transaction(function () use ($inbox) {
                $locked = CommunicationInbox::query()
                    ->whereKey($inbox->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $entry = $this->outbox->enqueue(
                    $locked,
                    GatewayCommandType::ConnectSession,
                    [],
                );
                $locked->forceFill([
                    'status' => InboxStatus::Connecting,
                    'revoked_at' => null,
                    'lock_version' => (int) $locked->lock_version + 1,
                ])->save();

                return $entry;
            });
        } catch (Throwable $error) {
            $this->pairing->forget((int) $inbox->id);

            throw $error;
        }

        $state['command_id'] = $entry->command_id;
        $state['type'] = $entry->type->value;
        $state['commands'] = [$entry->command_id];
        $this->pairing->put((int) $inbox->id, $state);

        return new CommunicationInboxPairingResult($state);
    }
}
