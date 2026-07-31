<?php

namespace App\Actions\Communication;

use App\DTO\Communication\InboxUpdateData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Exceptions\CommunicationInboxApiException;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Outbox\OutboxService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class UpdateInboxAction
{
    public function __construct(
        private OutboxService $outbox,
        private EventRecorder $events,
        private CurrentTenant $currentTenant,
    ) {}

    public function handle(
        CommunicationInbox $inbox,
        InboxUpdateData $data,
    ): CommunicationInbox {
        return DB::transaction(function () use ($inbox, $data): CommunicationInbox {
            if ($data->isDefault === true) {
                Tenant::query()->whereKey($inbox->tenant_id)->lockForUpdate()->firstOrFail();
                CommunicationInbox::query()
                    ->whereKeyNot($inbox->id)
                    ->update(['is_default' => false]);
            }

            $attributes = [];
            if ($data->name !== null) {
                $attributes['name'] = $data->name;
            }
            if ($data->isEnabled !== null) {
                $attributes['is_enabled'] = $data->isEnabled;
            }
            if ($data->isDefault !== null) {
                $attributes['is_default'] = $data->isDefault;
            }
            if ($data->hasWorkDepartmentId) {
                $attributes['work_department_id'] = $data->workDepartmentId;
            }

            $disabling = $inbox->is_enabled && $data->isEnabled === false;
            if ($disabling
                && config('communication.enabled')
                && config('communication.gateway.enabled')) {
                $this->outbox->enqueue($inbox, GatewayCommandType::DisconnectSession, []);
                $attributes['status'] = InboxStatus::Disconnected;
            }

            $attributes['lock_version'] = $data->lockVersion + 1;
            $changed = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->where('lock_version', $data->lockVersion)
                ->update($attributes);
            if ($changed !== 1) {
                throw CommunicationInboxApiException::versionConflict();
            }

            $updated = $inbox->fresh();
            if (! $updated instanceof CommunicationInbox) {
                throw CommunicationInboxApiException::versionConflict();
            }

            $this->events->record((int) $updated->tenant_id, 'INBOX_UPDATED', [
                'inbox_id' => (int) $updated->id,
                'lock_version' => (int) $updated->lock_version,
            ], inboxId: (int) $updated->id,
                actorMembershipId: $this->currentTenant->realMembership()?->id);

            return $updated;
        });
    }
}
