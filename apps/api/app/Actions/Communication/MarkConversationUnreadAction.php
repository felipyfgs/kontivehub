<?php

namespace App\Actions\Communication;

use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationConversation;
use App\Services\Communication\Conversation\ConversationReadStateService;
use App\Services\Communication\ConversationCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class MarkConversationUnreadAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private ConversationCanonicalizer $canonicalizer,
        private ConversationReadStateService $readState,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        int $expectedVersion,
    ): CommunicationConversation {
        return DB::transaction(function () use ($conversation, $expectedVersion): CommunicationConversation {
            $fresh = $this->canonicalizer->lockConversation($conversation);
            if ($fresh->purged_at !== null) {
                throw CommunicationConversationApiException::purged();
            }

            $this->readState->markUnread(
                $fresh,
                $expectedVersion,
                $this->currentTenant->actor()?->id,
                $this->currentTenant->realMembership()?->id,
            );

            return $this->readState->project($fresh->load([
                'identity.contact',
                'clients',
                'labels',
                'latestMessage.attachments',
            ]));
        }, 3);
    }
}
