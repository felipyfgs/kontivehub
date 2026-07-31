<?php

namespace App\Actions\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class RemoveConversationLabelAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private ConversationCanonicalizer $canonicalizer,
        private EventRecorder $events,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        CommunicationLabel $label,
    ): void {
        DB::transaction(function () use ($conversation, $label): void {
            $canonical = $this->canonicalizer->lockConversation($conversation);
            $detached = $canonical->labels()->detach($label->id);

            if ($detached === 0) {
                return;
            }

            $this->events->record(
                (int) $canonical->tenant_id,
                'CONVERSATION_LABELS_UPDATED',
                [
                    'action' => 'REMOVE',
                    'label_id' => (int) $label->id,
                    'lock_version' => (int) $canonical->lock_version,
                ],
                inboxId: (int) $canonical->inbox_id,
                conversationId: (int) $canonical->id,
                actorMembershipId: $this->currentTenant->realMembership()?->id,
            );
        });
    }
}
