<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationInboxCreationData;
use App\Enums\Communication\InboxStatus;
use App\Models\CommunicationInbox;
use App\Models\Tenant;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class CreateCommunicationInboxAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationEventRecorder $events,
    ) {}

    public function handle(CommunicationInboxCreationData $data): CommunicationInbox
    {
        return DB::transaction(function () use ($data): CommunicationInbox {
            $tenant = $this->currentTenant->tenant();
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();

            if ($data->isDefault) {
                CommunicationInbox::query()->update(['is_default' => false]);
            }

            $inbox = CommunicationInbox::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data->name,
                'session_id' => 'session-'.strtolower((string) Str::ulid()),
                'status' => InboxStatus::Disconnected,
                'is_enabled' => $data->isEnabled,
                'is_default' => $data->isDefault,
                'work_department_id' => $data->workDepartmentId,
            ]);

            $this->events->record((int) $tenant->id, 'INBOX_CREATED', [
                'inbox_id' => (int) $inbox->id,
                'name' => $inbox->name,
            ], inboxId: (int) $inbox->id,
                actorMembershipId: $this->currentTenant->realMembership()?->id);

            return $inbox->refresh();
        });
    }
}
