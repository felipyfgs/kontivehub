<?php

namespace App\Actions\Communication;

use App\DTO\Communication\TenantSettingsData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Outbox\OutboxService;
use App\Services\Communication\Pairing\PairingStateStore;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantSettingsAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private OutboxService $outbox,
        private PairingStateStore $pairing,
        private EventRecorder $events,
    ) {}

    public function handle(
        TenantSettingsData $data,
    ): TenantSettingsData {
        $pairingInboxIds = DB::transaction(function () use ($data): array {
            $tenant = Tenant::query()
                ->whereKey($this->currentTenant->tenant()->id)
                ->lockForUpdate()
                ->firstOrFail();
            $pairingInboxIds = [];

            if ($tenant->communication_enabled
                && ! $data->enabled
                && config('communication.enabled')
                && config('communication.gateway.enabled')) {
                foreach (
                    CommunicationInbox::query()
                        ->where('is_enabled', true)
                        ->lazyById(100) as $inbox
                ) {
                    $this->outbox->enqueue($inbox, GatewayCommandType::DisconnectSession, []);
                    $inbox->forceFill([
                        'status' => InboxStatus::Disconnected,
                        'lock_version' => (int) $inbox->lock_version + 1,
                    ])->save();
                    $pairingInboxIds[] = (int) $inbox->id;
                }
            }

            $tenant->forceFill(['communication_enabled' => $data->enabled])->save();
            $this->events->record((int) $tenant->id, 'TENANT_COMMUNICATION_SWITCH_CHANGED', [
                'enabled' => (bool) $tenant->communication_enabled,
            ], actorMembershipId: $this->currentTenant->realMembership()?->id);

            return $pairingInboxIds;
        });

        foreach ($pairingInboxIds as $inboxId) {
            $this->pairing->forget($inboxId);
        }

        return $data;
    }
}
