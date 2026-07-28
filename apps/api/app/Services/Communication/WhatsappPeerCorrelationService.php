<?php

namespace App\Services\Communication;

use App\Enums\Communication\ConversationStatus;
use App\Enums\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * Correlaciona aliases 1:1 comprovados pelo gateway dentro da transação do evento.
 */
final readonly class WhatsappPeerCorrelationService
{
    public function __construct(
        private WhatsappAddressNormalizer $normalizer,
    ) {}

    /**
     * @param  list<string>  $aliases
     * @return array{0:CommunicationIdentity,1:CommunicationConversation}
     */
    public function correlate(
        CommunicationInbox $inbox,
        string $canonicalAddress,
        array $aliases,
        bool $history,
        DateTimeInterface $occurredAt,
    ): array {
        $aliases = array_values(array_unique([...$aliases, $canonicalAddress]));
        sort($aliases, SORT_STRING);

        $this->lockAliases($inbox, $aliases);
        $this->closeLegacySelfConversations($inbox, $occurredAt);
        [$identity, $identityIds] = $this->correlateIdentities(
            $inbox,
            $canonicalAddress,
            $aliases,
            $occurredAt,
        );
        $conversation = $this->correlateConversation(
            $inbox,
            $identity,
            $identityIds,
            $history,
            $occurredAt,
        );

        return [$identity, $conversation];
    }

    /**
     * @param  list<string>  $aliases
     */
    private function lockAliases(CommunicationInbox $inbox, array $aliases): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('A correlação WhatsApp exige uma transação ativa.');
        }

        foreach ($aliases as $alias) {
            $bytes = substr(hash(
                'sha256',
                $inbox->tenant_id.'|'.$inbox->id.'|'.$alias,
                true,
            ), 0, 8);
            /** @var array{scope:int,alias:int} $parts */
            $parts = unpack('Nscope/Nalias', $bytes);
            DB::select(
                'SELECT pg_advisory_xact_lock(?, ?)',
                [$this->signedInt32($parts['scope']), $this->signedInt32($parts['alias'])],
            );
        }
    }

    /**
     * @param  list<string>  $aliases
     * @return array{0:CommunicationIdentity,1:list<int>}
     */
    private function correlateIdentities(
        CommunicationInbox $inbox,
        string $canonicalAddress,
        array $aliases,
        DateTimeInterface $seenAt,
    ): array {
        $hashes = array_map(
            static fn (string $alias): string => hash('sha256', $alias),
            $aliases,
        );
        $matches = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::Whatsapp->value)
            ->whereIn('address_hash', $hashes)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $canonicalHash = hash('sha256', $canonicalAddress);
        $identity = $matches->first(
            static fn (CommunicationIdentity $row): bool => hash_equals(
                (string) $row->address_hash,
                $canonicalHash,
            ),
        );
        $contactId = $identity?->contact_id ?? $matches->first()?->contact_id;
        $previousContactIds = $matches
            ->pluck('contact_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($contactId === null) {
            $contactId = CommunicationContact::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'is_provisional' => true,
                'is_active' => true,
            ])->id;
        }

        foreach ($matches as $match) {
            $match->forceFill([
                'contact_id' => $contactId,
                'last_seen_at' => $seenAt,
            ])->save();
        }
        $this->deleteOrphanProvisionalContacts(
            (int) $inbox->tenant_id,
            $previousContactIds,
            (int) $contactId,
        );

        /** @var array<string,CommunicationIdentity> $byHash */
        $byHash = $matches->keyBy('address_hash')->all();
        foreach ($aliases as $alias) {
            $hash = hash('sha256', $alias);
            if (isset($byHash[$hash])) {
                continue;
            }

            $byHash[$hash] = CommunicationIdentity::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'contact_id' => $contactId,
                'channel' => CommunicationChannel::Whatsapp,
                'address_encrypted' => $alias,
                'address_hash' => $hash,
                'address_masked' => $this->maskAddress($alias),
                'is_active' => true,
                'last_seen_at' => $seenAt,
            ]);
        }

        $identity = $byHash[$canonicalHash];

        return [
            $identity,
            array_values(array_map(
                static fn (CommunicationIdentity $row): int => (int) $row->id,
                $byHash,
            )),
        ];
    }

    private function closeLegacySelfConversations(
        CommunicationInbox $inbox,
        DateTimeInterface $occurredAt,
    ): void {
        $raw = trim((string) ($inbox->address_encrypted ?? ''));
        if ($raw === '') {
            return;
        }

        try {
            $sessionAddress = $this->normalizer->normalize($raw);
        } catch (InvalidArgumentException) {
            return;
        }

        $identityId = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::Whatsapp->value)
            ->where('address_hash', hash('sha256', $sessionAddress))
            ->value('id');
        if ($identityId === null) {
            return;
        }

        CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $identityId)
            ->where('status', '<>', ConversationStatus::Resolved->value)
            ->lockForUpdate()
            ->get()
            ->each(static function (CommunicationConversation $conversation) use ($occurredAt): void {
                $conversation->forceFill([
                    'status' => ConversationStatus::Resolved,
                    'resolved_at' => $occurredAt,
                    'assignee_membership_id' => null,
                ])->save();
            });
    }

    /**
     * @param  list<int>  $contactIds
     */
    private function deleteOrphanProvisionalContacts(
        int $tenantId,
        array $contactIds,
        int $survivorId,
    ): void {
        $loserIds = array_values(array_filter(
            $contactIds,
            static fn (int $contactId): bool => $contactId !== $survivorId,
        ));
        if ($loserIds === []) {
            return;
        }

        DB::table('communication_contacts')
            ->where('tenant_id', $tenantId)
            ->whereIn('id', $loserIds)
            ->where('is_provisional', true)
            ->whereNotExists(static fn ($query) => $query
                ->selectRaw('1')
                ->from('communication_identities')
                ->whereColumn('communication_identities.contact_id', 'communication_contacts.id'))
            ->delete();
    }

    /**
     * @param  list<int>  $identityIds
     */
    private function correlateConversation(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
        array $identityIds,
        bool $history,
        DateTimeInterface $occurredAt,
    ): CommunicationConversation {
        $conversations = CommunicationConversation::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->whereIn('identity_id', $identityIds)
            ->orderByRaw(
                'CASE WHEN status <> ? THEN 0 ELSE 1 END',
                [ConversationStatus::Resolved->value],
            )
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->get();

        /** @var CommunicationConversation|null $conversation */
        $conversation = $conversations->first();
        if ($conversation === null) {
            $conversation = CommunicationConversation::query()->withoutGlobalScopes()->create([
                'tenant_id' => $inbox->tenant_id,
                'inbox_id' => $inbox->id,
                'identity_id' => $identity->id,
                'status' => $history ? ConversationStatus::Resolved : ConversationStatus::Open,
                'work_department_id' => $inbox->work_department_id,
                'last_message_at' => $occurredAt,
                'resolved_at' => $history ? $occurredAt : null,
            ]);
        }

        $activeDonors = $conversations
            ->filter(static fn (CommunicationConversation $candidate): bool => (
                (int) $candidate->id !== (int) $conversation->id
                && $candidate->status !== ConversationStatus::Resolved
            ));
        $this->mergeActiveConversations($inbox, $conversation, $activeDonors, $occurredAt);

        $updates = ['identity_id' => $identity->id];
        if (! $history && $conversation->status === ConversationStatus::Resolved) {
            $updates['status'] = ConversationStatus::Open;
            $updates['resolved_at'] = null;
        }
        $conversation->forceFill($updates)->save();
        $this->attachIdentityClients($inbox, $conversation, $identityIds);

        return $conversation;
    }

    /**
     * @param  Collection<int,CommunicationConversation>  $donors
     */
    private function mergeActiveConversations(
        CommunicationInbox $inbox,
        CommunicationConversation $survivor,
        Collection $donors,
        DateTimeInterface $occurredAt,
    ): void {
        foreach ($donors as $donor) {
            $this->copyConversationClients($inbox, $donor, $survivor);
            $this->copyConversationLabels($inbox, $donor, $survivor);

            CommunicationMessage::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('communication_events')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('client_communication_dispatches')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);
            DB::table('communication_flow_consumptions')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('conversation_id', $donor->id)
                ->update(['conversation_id' => $survivor->id]);

            $survivor->forceFill([
                'priority' => max((int) $survivor->priority, (int) $donor->priority),
                'assignee_membership_id' => $survivor->assignee_membership_id
                    ?? $donor->assignee_membership_id,
                'work_department_id' => $survivor->work_department_id
                    ?? $donor->work_department_id,
                'last_message_at' => $this->latestDate(
                    $survivor->last_message_at,
                    $donor->last_message_at,
                ),
            ])->save();
            $donor->forceFill([
                'status' => ConversationStatus::Resolved,
                'resolved_at' => $occurredAt,
                'assignee_membership_id' => null,
            ])->save();
        }
    }

    /**
     * @param  list<int>  $identityIds
     */
    private function attachIdentityClients(
        CommunicationInbox $inbox,
        CommunicationConversation $conversation,
        array $identityIds,
    ): void {
        $clientIds = DB::table('communication_identity_links')
            ->where('tenant_id', $inbox->tenant_id)
            ->whereIn('identity_id', $identityIds)
            ->pluck('client_id');
        foreach ($clientIds as $clientId) {
            DB::table('communication_conversation_clients')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $conversation->id,
                'client_id' => $clientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function copyConversationClients(
        CommunicationInbox $inbox,
        CommunicationConversation $from,
        CommunicationConversation $to,
    ): void {
        $clientIds = DB::table('communication_conversation_clients')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('conversation_id', $from->id)
            ->pluck('client_id');
        foreach ($clientIds as $clientId) {
            DB::table('communication_conversation_clients')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $to->id,
                'client_id' => $clientId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function copyConversationLabels(
        CommunicationInbox $inbox,
        CommunicationConversation $from,
        CommunicationConversation $to,
    ): void {
        $labels = DB::table('communication_conversation_labels')
            ->where('tenant_id', $inbox->tenant_id)
            ->where('conversation_id', $from->id)
            ->get(['label_id', 'assigned_by_membership_id']);
        foreach ($labels as $label) {
            DB::table('communication_conversation_labels')->insertOrIgnore([
                'tenant_id' => $inbox->tenant_id,
                'conversation_id' => $to->id,
                'label_id' => $label->label_id,
                'assigned_by_membership_id' => $label->assigned_by_membership_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function latestDate(?DateTimeInterface $left, ?DateTimeInterface $right): ?DateTimeInterface
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left >= $right ? $left : $right;
    }

    private function signedInt32(int $value): int
    {
        return $value > 0x7fffffff ? $value - 0x100000000 : $value;
    }

    private function maskAddress(string $address): string
    {
        return str_starts_with($address, 'lid:')
            ? 'lid:***'.substr($address, -4)
            : '***'.substr($address, -4);
    }
}
