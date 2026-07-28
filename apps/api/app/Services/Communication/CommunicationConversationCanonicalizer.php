<?php

namespace App\Services\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Resolve redirects persistidos sem inferir equivalência por contact_id.
 */
final class CommunicationConversationCanonicalizer
{
    private const MAX_REDIRECTS = 16;

    public function identity(CommunicationIdentity $identity): CommunicationIdentity
    {
        $tenantId = (int) $identity->tenant_id;
        $channel = $identity->channel;
        $current = $identity;
        $visited = [];

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            if (isset($visited[$current->id])) {
                throw new LogicException('Ciclo detectado na identity canônica.');
            }
            $visited[$current->id] = true;
            $targetId = $current->canonical_identity_id !== null
                ? (int) $current->canonical_identity_id
                : (int) $current->id;
            $current = CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('channel', $channel)
                ->findOrFail($targetId);
            if ($current->canonical_identity_id === null) {
                return $current;
            }
        }

        throw new LogicException('Cadeia de identity canônica excede o limite.');
    }

    /**
     * @return list<int>
     */
    public function identityIds(CommunicationIdentity $identity): array
    {
        $canonical = $this->identity($identity);

        return CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $canonical->tenant_id)
            ->where('channel', $canonical->channel)
            ->where(static fn ($query) => $query
                ->whereKey($canonical->id)
                ->orWhere('canonical_identity_id', $canonical->id))
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    public function conversation(CommunicationConversation $conversation): CommunicationConversation
    {
        return $this->resolveConversation($conversation);
    }

    public function lockConversation(CommunicationConversation $conversation): CommunicationConversation
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('O bloqueio de conversation canônica exige uma transação ativa.');
        }

        $tenantId = (int) $conversation->tenant_id;
        $inboxId = (int) $conversation->inbox_id;
        $nextId = (int) $conversation->id;
        $visited = [];

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            if (isset($visited[$nextId])) {
                throw new LogicException('Ciclo detectado no redirecionamento de conversation.');
            }
            $visited[$nextId] = true;
            $locked = CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->whereKey($nextId)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->merged_into_conversation_id === null) {
                return $locked;
            }
            $nextId = (int) $locked->merged_into_conversation_id;
        }

        throw new LogicException('Cadeia de redirecionamento de conversation excede o limite.');
    }

    private function resolveConversation(
        CommunicationConversation $conversation,
    ): CommunicationConversation {
        $tenantId = (int) $conversation->tenant_id;
        $inboxId = (int) $conversation->inbox_id;
        $current = $conversation;
        $visited = [];

        for ($depth = 0; $depth < self::MAX_REDIRECTS; $depth++) {
            if (isset($visited[$current->id])) {
                throw new LogicException('Ciclo detectado no redirecionamento de conversation.');
            }
            $visited[$current->id] = true;
            $targetId = $current->merged_into_conversation_id !== null
                ? (int) $current->merged_into_conversation_id
                : (int) $current->id;
            $query = CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('inbox_id', $inboxId)
                ->whereKey($targetId);
            $current = $query->firstOrFail();
            if ($current->merged_into_conversation_id === null) {
                return $current;
            }
        }

        throw new LogicException('Cadeia de redirecionamento de conversation excede o limite.');
    }
}
