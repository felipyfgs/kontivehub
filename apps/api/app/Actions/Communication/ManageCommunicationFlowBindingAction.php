<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationFlowBindingCreationData;
use App\DTO\Communication\CommunicationFlowBindingUpdateData;
use App\Exceptions\CommunicationFlowApiException;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowInboxBinding;
use App\Models\CommunicationFlowVersion;
use App\Models\CommunicationInbox;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Flows\CommunicationFlowAvailability;
use App\Services\Communication\Flows\CommunicationFlowRunControlService;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class ManageCommunicationFlowBindingAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationFlowAvailability $availability,
        private CommunicationFlowRunControlService $runControl,
        private CommunicationEventRecorder $events,
    ) {}

    public function create(
        CommunicationFlow $flow,
        CommunicationFlowBindingCreationData $data,
    ): CommunicationFlowInboxBinding {
        $this->ensureEnabled();
        $this->assertVersionOfFlow($flow, $data->publishedVersionId);
        if ($data->enabled && $data->publishedVersionId === null) {
            throw CommunicationFlowApiException::publishedVersionRequired();
        }
        $inbox = CommunicationInbox::query()->findOrFail($data->inboxId);
        if ((int) $inbox->tenant_id !== (int) $flow->tenant_id) {
            abort(404);
        }
        $membershipId = $this->currentTenant->realMembership()?->id;

        try {
            return DB::transaction(function () use ($flow, $data, $inbox, $membershipId): CommunicationFlowInboxBinding {
                $binding = CommunicationFlowInboxBinding::query()->create([
                    'tenant_id' => $flow->tenant_id,
                    'flow_id' => $flow->id,
                    'inbox_id' => $inbox->id,
                    'published_version_id' => $data->publishedVersionId,
                    'enabled' => $data->enabled,
                    'lock_version' => 1,
                ]);
                $this->record($binding, 'COMMUNICATION_FLOW_BINDING_CREATED', $membershipId);

                return $binding;
            });
        } catch (QueryException $error) {
            $this->throwExpectedConflict($error);
        }
    }

    public function update(
        CommunicationFlowInboxBinding $binding,
        CommunicationFlowBindingUpdateData $data,
    ): CommunicationFlowInboxBinding {
        return $this->change($binding, $data, null);
    }

    public function setEnabled(
        CommunicationFlowInboxBinding $binding,
        CommunicationFlowBindingUpdateData $data,
        bool $enabled,
    ): CommunicationFlowInboxBinding {
        return $this->change($binding, $data, $enabled);
    }

    public function delete(CommunicationFlowInboxBinding $binding): void
    {
        $this->ensureEnabled();
        $membershipId = $this->currentTenant->realMembership()?->id;

        DB::transaction(function () use ($binding, $membershipId): void {
            $locked = CommunicationFlowInboxBinding::query()
                ->whereKey($binding->id)
                ->lockForUpdate()
                ->firstOrFail();
            $payload = [
                'flow_id' => (int) $locked->flow_id,
                'binding_id' => (int) $locked->id,
                'inbox_id' => (int) $locked->inbox_id,
            ];
            $tenantId = (int) $locked->tenant_id;
            $inboxId = (int) $locked->inbox_id;
            if ((bool) $locked->enabled) {
                $this->runControl->stopActiveForBinding(
                    (int) $locked->id,
                    'binding_deleted',
                );
            }
            $locked->delete();
            $this->events->record(
                $tenantId,
                'COMMUNICATION_FLOW_BINDING_DELETED',
                $payload,
                inboxId: $inboxId,
                actorMembershipId: $membershipId,
            );
        });
    }

    private function change(
        CommunicationFlowInboxBinding $binding,
        CommunicationFlowBindingUpdateData $data,
        ?bool $forcedEnabled,
    ): CommunicationFlowInboxBinding {
        $this->ensureEnabled();
        $membershipId = $this->currentTenant->realMembership()?->id;

        try {
            return DB::transaction(function () use ($binding, $data, $forcedEnabled, $membershipId): CommunicationFlowInboxBinding {
                $fresh = CommunicationFlowInboxBinding::query()
                    ->whereKey($binding->id)
                    ->lockForUpdate()
                    ->first();
                if ($fresh === null || (int) $fresh->lock_version !== $data->lockVersion) {
                    throw CommunicationFlowApiException::bindingVersionConflict();
                }
                $wasEnabled = (bool) $fresh->enabled;
                $enabled = $forcedEnabled
                    ?? $data->enabled
                    ?? $wasEnabled;
                $versionId = $data->hasPublishedVersionId
                    ? $data->publishedVersionId
                    : ($fresh->published_version_id !== null
                        ? (int) $fresh->published_version_id
                        : null);
                $flow = CommunicationFlow::query()->findOrFail((int) $fresh->flow_id);
                $this->assertVersionOfFlow($flow, $versionId);
                if ($enabled && $versionId === null) {
                    throw CommunicationFlowApiException::publishedVersionRequired();
                }

                $fresh->fill([
                    'published_version_id' => $versionId,
                    'enabled' => $enabled,
                    'lock_version' => $data->lockVersion + 1,
                ]);
                $fresh->save();
                if ($wasEnabled && ! $enabled) {
                    $this->runControl->stopActiveForBinding(
                        (int) $fresh->id,
                        'binding_disabled',
                    );
                }
                $this->record($fresh, 'COMMUNICATION_FLOW_BINDING_UPDATED', $membershipId);

                return $fresh;
            });
        } catch (QueryException $error) {
            $this->throwExpectedConflict($error);
        }
    }

    private function assertVersionOfFlow(
        CommunicationFlow $flow,
        ?int $versionId,
    ): void {
        if ($versionId === null) {
            return;
        }
        $exists = CommunicationFlowVersion::query()
            ->where('flow_id', $flow->id)
            ->whereKey($versionId)
            ->exists();
        if (! $exists) {
            throw CommunicationFlowApiException::invalidPublishedVersion();
        }
    }

    private function record(
        CommunicationFlowInboxBinding $binding,
        string $type,
        ?int $membershipId,
    ): void {
        $this->events->record((int) $binding->tenant_id, $type, [
            'flow_id' => (int) $binding->flow_id,
            'binding_id' => (int) $binding->id,
            'inbox_id' => (int) $binding->inbox_id,
            'enabled' => (bool) $binding->enabled,
            'published_version_id' => $binding->published_version_id,
            'lock_version' => (int) $binding->lock_version,
        ], inboxId: (int) $binding->inbox_id,
            actorMembershipId: $membershipId);
    }

    private function ensureEnabled(): void
    {
        if (! $this->availability->enabled()) {
            throw CommunicationFlowApiException::disabled();
        }
    }

    private function throwExpectedConflict(QueryException $error): never
    {
        $message = mb_strtolower($error->getMessage());
        if ((string) $error->getCode() === '23505'
            && (str_contains($message, 'communication_flow_bindings_one_enabled_per_inbox')
                || str_contains($message, 'communication_flow_inbox_bindings_flow_id_inbox_id_unique'))) {
            throw CommunicationFlowApiException::enabledBindingConflict();
        }

        throw $error;
    }
}
