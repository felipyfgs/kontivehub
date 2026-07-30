<?php

namespace App\Actions\Communication;

use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationConversation;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Services\Communication\Conversation\CommunicationConversationReadStateService;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class MarkCommunicationConversationReadAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationConversationCanonicalizer $canonicalizer,
        private CommunicationConversationReadStateService $readState,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        int $throughMessageId,
    ): CommunicationConversation {
        return DB::transaction(function () use ($conversation, $throughMessageId): CommunicationConversation {
            $fresh = $this->canonicalizer->lockConversation($conversation);
            if ($fresh->purged_at !== null) {
                throw CommunicationConversationApiException::purged();
            }

            $this->readState->markRead(
                $fresh,
                (int) $throughMessageId,
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
