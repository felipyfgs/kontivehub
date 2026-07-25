<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\FlowStatus;
use App\Jobs\Communication\AdvanceCommunicationFlowRunJob;
use App\Models\CommunicationFlowRun;
use App\Models\OfficeMembership;
use App\Services\Communication\Events\CommunicationEventRecorder;
use DomainException;
use Illuminate\Support\Facades\DB;

final class CommunicationFlowRunControlService
{
    public function __construct(
        private readonly CommunicationFlowAvailability $availability,
        private readonly CommunicationFlowLock $locks,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function pause(CommunicationFlowRun $run, ?OfficeMembership $actor = null): CommunicationFlowRun
    {
        $this->availability->assertEnabled();

        return $this->transition($run, FlowRunStatus::Paused, 'COMMUNICATION_FLOW_RUN_PAUSED', $actor, requireNonTerminal: true);
    }

    public function resume(CommunicationFlowRun $run, ?OfficeMembership $actor = null): CommunicationFlowRun
    {
        $this->availability->assertRuntimeEnabled();

        $updated = DB::transaction(function () use ($run, $actor): CommunicationFlowRun {
            if ($run->conversation_id !== null) {
                $this->locks->lockConversation((int) $run->conversation_id);
            }
            $locked = $this->locks->lockRun((int) $run->id);
            if ($locked->status !== FlowRunStatus::Paused) {
                throw new DomainException('FLOW_RUN_NOT_PAUSED');
            }
            $locked->loadMissing(['flow', 'binding']);
            if ($locked->flow?->status === FlowStatus::Paused || $locked->binding?->enabled !== true) {
                throw new DomainException('FLOW_RUN_NOT_ELIGIBLE');
            }
            $locked->forceFill(['status' => FlowRunStatus::Running])->save();
            $this->record($locked, 'COMMUNICATION_FLOW_RUN_RESUMED', $actor);

            return $locked->fresh() ?? $locked;
        });

        AdvanceCommunicationFlowRunJob::dispatch((int) $updated->id);

        return $updated;
    }

    public function handoff(CommunicationFlowRun $run, ?OfficeMembership $actor = null): CommunicationFlowRun
    {
        $this->availability->assertEnabled();

        return $this->transition($run, FlowRunStatus::HandedOff, 'COMMUNICATION_FLOW_RUN_HANDED_OFF', $actor, requireNonTerminal: true, finish: true);
    }

    public function stop(CommunicationFlowRun $run, ?OfficeMembership $actor = null): CommunicationFlowRun
    {
        $this->availability->assertEnabled();

        return $this->transition($run, FlowRunStatus::Stopped, 'COMMUNICATION_FLOW_RUN_STOPPED', $actor, requireNonTerminal: true, finish: true);
    }

    public function restart(CommunicationFlowRun $run, ?OfficeMembership $actor = null): CommunicationFlowRun
    {
        $this->availability->assertRuntimeEnabled();

        $newRunId = DB::transaction(function () use ($run, $actor): int {
            if ($run->conversation_id !== null) {
                $this->locks->lockConversation((int) $run->conversation_id);
            }
            $locked = $this->locks->lockRun((int) $run->id);
            if (! $locked->status->isTerminal()) {
                $locked->forceFill([
                    'status' => FlowRunStatus::Stopped,
                    'finished_at' => now(),
                    'waiting_until' => null,
                    'waiting_effect_key' => null,
                    'waiting_outbox_entry_id' => null,
                ])->save();
                $this->record($locked, 'COMMUNICATION_FLOW_RUN_STOPPED', $actor, ['reason' => 'restart']);
            }

            $locked->loadMissing(['binding.publishedVersion', 'binding.flow', 'version']);
            $binding = $locked->binding;
            if ($binding === null || ! $binding->enabled || $binding->published_version_id === null) {
                throw new DomainException('FLOW_RUN_RESTART_NO_BINDING');
            }
            if ($binding->flow?->status === FlowStatus::Paused) {
                throw new DomainException('FLOW_RUN_RESTART_FLOW_PAUSED');
            }

            $version = $binding->publishedVersion ?? $locked->version;
            $graph = is_array($version?->graph_encrypted) ? $version->graph_encrypted : [];
            $startId = null;
            foreach (is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [] as $node) {
                if (is_array($node) && ($node['type'] ?? '') === 'start') {
                    $startId = (string) $node['id'];
                    break;
                }
            }
            if ($startId === null || $locked->conversation_id === null || $version === null) {
                throw new DomainException('FLOW_RUN_RESTART_INVALID');
            }

            $created = CommunicationFlowRun::query()->withoutGlobalScopes()->create([
                'office_id' => $locked->office_id,
                'flow_id' => $locked->flow_id,
                'flow_version_id' => $version->id,
                'binding_id' => $binding->id,
                'conversation_id' => $locked->conversation_id,
                'status' => FlowRunStatus::Pending,
                'current_node_id' => $startId,
                'context_encrypted' => [],
                'started_at' => now(),
            ]);
            $this->record($created, 'COMMUNICATION_FLOW_RUN_STARTED', $actor, [
                'reason' => 'restart',
                'previous_run_id' => (int) $locked->id,
            ]);

            return (int) $created->id;
        });

        AdvanceCommunicationFlowRunJob::dispatch($newRunId);

        return CommunicationFlowRun::query()->withoutGlobalScopes()->findOrFail($newRunId);
    }

    public function handoffActiveForConversation(
        int $conversationId,
        ?OfficeMembership $actor = null,
        string $reason = 'human_outbound',
    ): void {
        DB::transaction(function () use ($conversationId, $actor, $reason): void {
            $this->locks->lockConversation($conversationId);
            $active = $this->locks->findActiveRunForConversation($conversationId);
            if ($active === null) {
                return;
            }
            $active->forceFill([
                'status' => FlowRunStatus::HandedOff,
                'finished_at' => now(),
                'waiting_until' => null,
                'waiting_effect_key' => null,
                'waiting_outbox_entry_id' => null,
            ])->save();
            $this->record($active, 'COMMUNICATION_FLOW_RUN_HANDED_OFF', $actor, ['reason' => $reason]);
        });
    }

    public function terminateActiveForConversation(int $conversationId, FlowRunStatus $status, string $reason): void
    {
        DB::transaction(function () use ($conversationId, $status, $reason): void {
            $this->locks->lockConversation($conversationId);
            $active = $this->locks->findActiveRunForConversation($conversationId);
            if ($active === null) {
                return;
            }
            $active->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'waiting_until' => null,
                'waiting_effect_key' => null,
                'waiting_outbox_entry_id' => null,
            ])->save();
            $event = match ($status) {
                FlowRunStatus::Purged => 'COMMUNICATION_FLOW_RUN_PURGED',
                FlowRunStatus::Stopped => 'COMMUNICATION_FLOW_RUN_STOPPED',
                FlowRunStatus::HandedOff => 'COMMUNICATION_FLOW_RUN_HANDED_OFF',
                default => 'COMMUNICATION_FLOW_RUN_TERMINATED',
            };
            $this->record($active, $event, null, ['reason' => $reason]);
        });
    }

    public function stopActiveForFlow(int $flowId, string $reason = 'flow_paused'): int
    {
        $runIds = CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('flow_id', $flowId)
            ->whereIn('status', FlowRunStatus::nonTerminalValues())
            ->orderBy('id')
            ->pluck('id');

        $stopped = 0;
        foreach ($runIds as $runId) {
            if ($this->stopActiveRunById((int) $runId, $reason)) {
                $stopped++;
            }
        }

        return $stopped;
    }

    public function stopActiveForBinding(int $bindingId, string $reason = 'binding_disabled'): int
    {
        $runIds = CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('binding_id', $bindingId)
            ->whereIn('status', FlowRunStatus::nonTerminalValues())
            ->orderBy('id')
            ->pluck('id');

        $stopped = 0;
        foreach ($runIds as $runId) {
            if ($this->stopActiveRunById((int) $runId, $reason)) {
                $stopped++;
            }
        }

        return $stopped;
    }

    private function stopActiveRunById(int $runId, string $reason): bool
    {
        return DB::transaction(function () use ($runId, $reason): bool {
            $preview = CommunicationFlowRun::query()->withoutGlobalScopes()->find($runId);
            if ($preview === null || $preview->status->isTerminal()) {
                return false;
            }
            if ($preview->conversation_id !== null) {
                $this->locks->lockConversation((int) $preview->conversation_id);
            }
            $locked = $this->locks->lockRun($runId);
            if ($locked->status->isTerminal()) {
                return false;
            }
            $locked->forceFill([
                'status' => FlowRunStatus::Stopped,
                'finished_at' => now(),
                'waiting_until' => null,
                'waiting_effect_key' => null,
                'waiting_outbox_entry_id' => null,
            ])->save();
            $this->record($locked, 'COMMUNICATION_FLOW_RUN_STOPPED', null, ['reason' => $reason]);

            return true;
        });
    }

    private function transition(
        CommunicationFlowRun $run,
        FlowRunStatus $status,
        string $eventType,
        ?OfficeMembership $actor,
        bool $requireNonTerminal = false,
        bool $finish = false,
    ): CommunicationFlowRun {
        return DB::transaction(function () use ($run, $status, $eventType, $actor, $requireNonTerminal, $finish): CommunicationFlowRun {
            if ($run->conversation_id !== null) {
                $this->locks->lockConversation((int) $run->conversation_id);
            }
            $locked = $this->locks->lockRun((int) $run->id);
            if ($requireNonTerminal && $locked->status->isTerminal()) {
                throw new DomainException('FLOW_RUN_TERMINAL');
            }
            $payload = ['status' => $status];
            if ($finish) {
                $payload['finished_at'] = now();
                $payload['waiting_until'] = null;
                $payload['waiting_effect_key'] = null;
                $payload['waiting_outbox_entry_id'] = null;
            }
            $locked->forceFill($payload)->save();
            $this->record($locked, $eventType, $actor);

            return $locked->fresh() ?? $locked;
        });
    }

    /** @param array<string, mixed> $extra */
    private function record(
        CommunicationFlowRun $run,
        string $type,
        ?OfficeMembership $actor,
        array $extra = [],
    ): void {
        $this->events->record(
            (int) $run->office_id,
            $type,
            array_merge([
                'run_id' => (int) $run->id,
                'flow_id' => (int) $run->flow_id,
                'conversation_id' => $run->conversation_id !== null ? (int) $run->conversation_id : null,
                'status' => $run->status instanceof FlowRunStatus ? $run->status->value : (string) $run->status,
            ], $extra),
            conversationId: $run->conversation_id !== null ? (int) $run->conversation_id : null,
            actorMembershipId: $actor?->id,
        );
    }
}
