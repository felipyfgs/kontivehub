<?php

namespace App\Services\Communication\Flows;

use App\Enums\Communication\FlowRunStatus;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlowRun;
use Illuminate\Support\Facades\DB;

/**
 * Locks canônicos: conversation → flow_run.
 */
final class FlowLock
{
    public function lockConversation(int $conversationId): CommunicationConversation
    {
        /** @var CommunicationConversation $conversation */
        $conversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->whereKey($conversationId)
            ->lockForUpdate()
            ->firstOrFail();

        return $conversation;
    }

    public function lockRun(int $runId): CommunicationFlowRun
    {
        /** @var CommunicationFlowRun $run */
        $run = CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->whereKey($runId)
            ->lockForUpdate()
            ->firstOrFail();

        return $run;
    }

    /**
     * @template T
     *
     * @param  callable(CommunicationConversation, CommunicationFlowRun): T  $callback
     * @return T
     */
    public function withConversationAndRun(int $conversationId, int $runId, callable $callback): mixed
    {
        return DB::transaction(function () use ($conversationId, $runId, $callback): mixed {
            $conversation = $this->lockConversation($conversationId);
            $run = $this->lockRun($runId);
            if ((int) $run->conversation_id !== (int) $conversation->id) {
                throw new \RuntimeException('FLOW_RUN_CONVERSATION_MISMATCH');
            }

            return $callback($conversation, $run);
        });
    }

    public function findActiveRunForConversation(int $conversationId): ?CommunicationFlowRun
    {
        return CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('conversation_id', $conversationId)
            ->whereIn('status', FlowRunStatus::nonTerminalValues())
            ->lockForUpdate()
            ->first();
    }
}
