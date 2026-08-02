<?php

namespace App\Services\Communication\Conversation;

use App\DTO\Communication\ConversationFiltersData;
use App\DTO\Communication\ConversationListSnapshotData;
use App\Exceptions\CommunicationConversationListSnapshotApiException;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Support\CurrentTenant;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use JsonException;
use Throwable;

final readonly class ConversationListSnapshotStore
{
    private const SCHEMA_VERSION = 1;

    private const TOKEN_BYTES = 32;

    private const TOKEN_ATTEMPTS = 3;

    public function __construct(
        private CacheFactory $cache,
        private Access $access,
        private CurrentTenant $currentTenant,
    ) {}

    /**
     * @param  list<int>  $visibleInboxIds
     * @param  list<int>  $conversationIds
     */
    public function create(
        User $actor,
        ConversationFiltersData $filters,
        array $visibleInboxIds,
        array $conversationIds,
    ): ConversationListSnapshotData {
        if (count($conversationIds) > $this->maximumConversationIds()) {
            throw CommunicationConversationListSnapshotApiException::tooLarge();
        }

        $context = $this->context($actor, $visibleInboxIds);
        $createdAt = CarbonImmutable::now('UTC');
        $expiresAt = $createdAt->addSeconds($this->ttlSeconds());
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'tenant_id' => $context['tenant_id'],
            'actor_id' => (int) $actor->id,
            'query_hash' => $this->queryHash($filters),
            'access_hash' => $context['access_hash'],
            'inboxes_hash' => $context['inboxes_hash'],
            'conversation_ids' => $conversationIds,
            'created_at' => $createdAt->getTimestamp(),
            'expires_at' => $expiresAt->getTimestamp(),
        ];

        try {
            $store = $this->cache->store($this->cacheStore());
            for ($attempt = 0; $attempt < self::TOKEN_ATTEMPTS; $attempt++) {
                $token = bin2hex(random_bytes(self::TOKEN_BYTES));
                if ($store->add(
                    $this->cacheKey($context['tenant_id'], (int) $actor->id, $token),
                    $payload,
                    $expiresAt,
                )) {
                    return new ConversationListSnapshotData(
                        token: $token,
                        conversationIds: $conversationIds,
                        expiresAt: $expiresAt->toIso8601String(),
                    );
                }
            }
        } catch (Throwable) {
            throw CommunicationConversationListSnapshotApiException::unavailable();
        }

        throw CommunicationConversationListSnapshotApiException::unavailable();
    }

    /** @param list<int> $visibleInboxIds */
    public function read(
        string $token,
        User $actor,
        ConversationFiltersData $filters,
        array $visibleInboxIds,
    ): ConversationListSnapshotData {
        $context = $this->context($actor, $visibleInboxIds);
        if (preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            throw CommunicationConversationListSnapshotApiException::expired();
        }

        try {
            $payload = $this->cache
                ->store($this->cacheStore())
                ->get($this->cacheKey($context['tenant_id'], (int) $actor->id, $token));
        } catch (Throwable) {
            throw CommunicationConversationListSnapshotApiException::unavailable();
        }

        if (! is_array($payload) || ! $this->payloadIsValid($payload)) {
            throw CommunicationConversationListSnapshotApiException::expired();
        }

        $expectedHashes = [
            'query_hash' => $this->queryHash($filters),
            'access_hash' => $context['access_hash'],
            'inboxes_hash' => $context['inboxes_hash'],
        ];
        if ((int) $payload['schema_version'] !== self::SCHEMA_VERSION
            || (int) $payload['tenant_id'] !== $context['tenant_id']
            || (int) $payload['actor_id'] !== (int) $actor->id
            || (int) $payload['expires_at'] <= CarbonImmutable::now('UTC')->getTimestamp()) {
            throw CommunicationConversationListSnapshotApiException::expired();
        }
        foreach ($expectedHashes as $field => $expected) {
            if (! hash_equals($expected, (string) $payload[$field])) {
                throw CommunicationConversationListSnapshotApiException::expired();
            }
        }

        /** @var list<int> $conversationIds */
        $conversationIds = array_map(
            static fn (mixed $id): int => (int) $id,
            array_values($payload['conversation_ids']),
        );
        $expiresAt = CarbonImmutable::createFromTimestampUTC((int) $payload['expires_at']);

        return new ConversationListSnapshotData(
            token: $token,
            conversationIds: $conversationIds,
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    public function maximumConversationIds(): int
    {
        return max(1, (int) config('communication.conversation_list_snapshot.max_ids', 10_000));
    }

    /**
     * @param  list<int>  $visibleInboxIds
     * @return array{tenant_id: int, access_hash: string, inboxes_hash: string}
     */
    private function context(User $actor, array $visibleInboxIds): array
    {
        $tenant = $this->currentTenant->resolve($actor);
        if ($tenant === null || ! $this->access->canView($actor)) {
            throw CommunicationConversationListSnapshotApiException::expired();
        }

        $actualInboxIds = $this->normalizeIds($this->access->visibleInboxIds($actor));
        if ($actualInboxIds !== $this->normalizeIds($visibleInboxIds)) {
            throw CommunicationConversationListSnapshotApiException::expired();
        }
        $membership = $this->currentTenant->realMembership();

        return [
            'tenant_id' => (int) $tenant->id,
            'access_hash' => $this->hash([
                'mode' => $this->currentTenant->accessMode()?->value,
                'role' => $this->currentTenant->role()?->value,
                'membership_id' => $membership?->id === null ? null : (int) $membership->id,
                'authorization_version' => $membership?->authorization_version === null
                    ? null
                    : (int) $membership->authorization_version,
            ]),
            'inboxes_hash' => $this->hash($actualInboxIds),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function payloadIsValid(array $payload): bool
    {
        foreach ([
            'schema_version',
            'tenant_id',
            'actor_id',
            'query_hash',
            'access_hash',
            'inboxes_hash',
            'conversation_ids',
            'created_at',
            'expires_at',
        ] as $field) {
            if (! array_key_exists($field, $payload)) {
                return false;
            }
        }
        if (! is_int($payload['schema_version'])
            || ! is_int($payload['tenant_id'])
            || ! is_int($payload['actor_id'])
            || ! is_string($payload['query_hash'])
            || ! is_string($payload['access_hash'])
            || ! is_string($payload['inboxes_hash'])
            || ! is_array($payload['conversation_ids'])
            || ! is_int($payload['created_at'])
            || ! is_int($payload['expires_at'])
            || count($payload['conversation_ids']) > $this->maximumConversationIds()) {
            return false;
        }

        foreach ($payload['conversation_ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                return false;
            }
        }

        if (count(array_unique($payload['conversation_ids'])) !== count($payload['conversation_ids'])
            || $payload['created_at'] >= $payload['expires_at']
            || ($payload['expires_at'] - $payload['created_at']) > 28_800) {
            return false;
        }

        return true;
    }

    private function queryHash(ConversationFiltersData $filters): string
    {
        $labelIds = $this->normalizeIds($filters->labelIds);

        return $this->hash([
            'inbox_id' => $filters->inboxId,
            'status' => $filters->status?->value,
            'assignee_membership_id' => $filters->assigneeMembershipId,
            'work_department_id' => $filters->workDepartmentId,
            'contact_id' => $filters->contactId,
            'unassigned' => $filters->unassigned,
            'unread' => $filters->unreadOnly,
            'q' => $filters->search,
            'label_ids' => $labelIds,
            'sort_by' => $filters->sortBy?->value,
            'per_page' => $filters->perPage,
        ]);
    }

    /** @param list<int> $ids @return list<int> */
    private function normalizeIds(array $ids): array
    {
        $normalized = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            $ids,
        )));
        sort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function cacheKey(int $tenantId, int $actorId, string $token): string
    {
        return 'tenant:'.$tenantId.':actor:'.$actorId
            .':communication:conversation-list-snapshot:'.hash('sha256', $token);
    }

    private function ttlSeconds(): int
    {
        return min(28_800, max(1, (int) config('communication.conversation_list_snapshot.ttl_seconds', 28_800)));
    }

    private function cacheStore(): string
    {
        return (string) config('communication.conversation_list_snapshot.cache_store', 'redis');
    }

    /** @param array<array-key, mixed> $value */
    private function hash(array $value): string
    {
        try {
            $encoded = json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw CommunicationConversationListSnapshotApiException::unavailable();
        }

        $key = trim((string) config('app.key'));
        if ($key === '') {
            throw CommunicationConversationListSnapshotApiException::unavailable();
        }

        return hash_hmac('sha256', $encoded, $key);
    }
}
