<?php

namespace App\Actions\Communication;

use App\DTO\Communication\GatewayCommandResult;
use App\DTO\Communication\GatewayOperationData;
use App\DTO\Communication\GatewayQueryResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationGatewayApiException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Models\User;
use App\Services\Communication\Contact\InboxIdentityProfileMerger;
use App\Services\Communication\Gateway\GatewayOperations;
use App\Services\Communication\Pairing\PairingStateStore;
use App\Services\Communication\ProfilePicture\ProfilePictureRefreshScheduler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ExecuteInboxGatewayAction
{
    public function __construct(
        private GatewayOperations $operations,
        private PairingStateStore $pairing,
        private InboxIdentityProfileMerger $identityProfiles,
        private ProfilePictureRefreshScheduler $profilePictures,
    ) {}

    public function command(
        User $actor,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        GatewayOperationData $data,
    ): GatewayCommandResult {
        if ($type === GatewayCommandType::DisconnectSession) {
            return $this->disconnect($actor, $inbox);
        }

        $entry = $this->operations->enqueue(
            $actor,
            $inbox,
            $type,
            $this->commandPayload($type, $data),
        );

        return GatewayCommandResult::fromEntry($entry);
    }

    public function query(
        User $actor,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        GatewayOperationData $data,
    ): GatewayQueryResult {
        if ($type === GatewayQueryType::ProfilePicture) {
            throw new LogicException('PROFILE_PICTURE must be scheduled asynchronously.');
        }
        $result = $this->operations->query(
            $actor,
            $inbox,
            $type,
            $this->queryPayload($type, $data),
        );
        if ($type === GatewayQueryType::UserInfo) {
            $this->observeVerifiedNames($inbox, $result);
        }

        return new GatewayQueryResult($result);
    }

    public function sessionStatus(
        User $actor,
        CommunicationInbox $inbox,
    ): GatewayQueryResult {
        return new GatewayQueryResult(
            $this->operations->sessionStatus($actor, $inbox),
        );
    }

    public function scheduleProfilePicture(
        CommunicationInbox $inbox,
        GatewayOperationData $data,
    ): GatewayQueryResult {
        $identity = CommunicationIdentity::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('channel', CommunicationChannel::WhatsApp->value)
            ->where('is_active', true)
            ->whereNull('purged_at')
            ->findOrFail((int) $data->parameters['identity_id']);
        $canonicalIdentityId = (int) ($identity->canonical_identity_id ?: $identity->id);
        $knownInInbox = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
            ->where('tenant_id', $inbox->tenant_id)
            ->where('inbox_id', $inbox->id)
            ->where('identity_id', $canonicalIdentityId)
            ->exists()
            || DB::table('communication_conversations as conversations')
                ->join('communication_identities as identities', function ($join): void {
                    $join->on('identities.id', '=', 'conversations.identity_id')
                        ->on('identities.tenant_id', '=', 'conversations.tenant_id');
                })
                ->where('conversations.tenant_id', $inbox->tenant_id)
                ->where('conversations.inbox_id', $inbox->id)
                ->whereRaw('COALESCE(identities.canonical_identity_id, identities.id) = ?', [$canonicalIdentityId])
                ->whereNull('conversations.purged_at')
                ->whereNull('conversations.merged_into_conversation_id')
                ->exists();
        if (! $knownInInbox) {
            throw (new ModelNotFoundException)->setModel(CommunicationIdentity::class, [(int) $identity->id]);
        }

        $this->profilePictures->schedule($inbox, $identity);

        // Preserve o envelope v1 sem expor URL/JID do upstream.
        return new GatewayQueryResult(['type' => GatewayQueryType::ProfilePicture->value]);
    }

    private function disconnect(
        User $actor,
        CommunicationInbox $inbox,
    ): GatewayCommandResult {
        $entry = DB::transaction(function () use ($actor, $inbox) {
            $locked = CommunicationInbox::query()
                ->whereKey($inbox->id)
                ->lockForUpdate()
                ->firstOrFail();
            $entry = $this->operations->enqueue(
                $actor,
                $locked,
                GatewayCommandType::DisconnectSession,
                [],
            );
            $locked->forceFill([
                'status' => InboxStatus::Disconnected,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();

            return $entry;
        });

        $this->pairing->forget((int) $inbox->id);

        return GatewayCommandResult::fromEntry($entry);
    }

    /** @return array<string, mixed> */
    private function commandPayload(
        GatewayCommandType $type,
        GatewayOperationData $data,
    ): array {
        if ($type !== GatewayCommandType::UpdateBlocklist) {
            return $data->parameters;
        }

        $identity = CommunicationIdentity::query()->findOrFail(
            (int) $data->parameters['identity_id'],
        );

        return [
            'to' => $this->identityAddress($identity),
            'action' => (string) $data->parameters['action'],
        ];
    }

    /** @return array<string, mixed> */
    private function queryPayload(
        GatewayQueryType $type,
        GatewayOperationData $data,
    ): array {
        return $data->parameters;
    }

    private function identityAddress(CommunicationIdentity $identity): string
    {
        $address = trim((string) $identity->address_encrypted);
        if ($address === '') {
            throw CommunicationGatewayApiException::identityAddressUnavailable();
        }

        return $address;
    }

    /** @param array<string, mixed> $result */
    private function observeVerifiedNames(CommunicationInbox $inbox, array $result): void
    {
        $observedAt = now()->utc();
        foreach (is_array($result['user_info'] ?? null) ? $result['user_info'] : [] as $item) {
            if (! is_array($item)) {
                continue;
            }
            $user = trim((string) ($item['user'] ?? ''));
            $verifiedName = trim((string) ($item['verified_name'] ?? ''));
            if ($user === '' || $verifiedName === '') {
                continue;
            }
            $identity = CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->where('channel', CommunicationChannel::WhatsApp->value)
                ->where('address_hash', hash('sha256', $user))
                ->first();
            if ($identity === null) {
                continue;
            }
            $identityIds = array_values(array_unique(array_filter([
                (int) $identity->id,
                $identity->canonical_identity_id !== null ? (int) $identity->canonical_identity_id : null,
            ])));
            $knownInInbox = DB::table('communication_conversations')
                ->where('tenant_id', $inbox->tenant_id)
                ->where('inbox_id', $inbox->id)
                ->whereIn('identity_id', $identityIds)
                ->exists();
            if (! $knownInInbox) {
                continue;
            }
            $this->identityProfiles->merge(
                $inbox,
                $identity,
                ['verified_name' => $verifiedName],
                $observedAt,
                'user-info:'.substr(hash('sha256', $user.'|'.$verifiedName.'|'.$observedAt->format('U.u')), 0, 48),
            );
        }
    }
}
