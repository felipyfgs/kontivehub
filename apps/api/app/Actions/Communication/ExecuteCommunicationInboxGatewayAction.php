<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationGatewayCommandResult;
use App\DTO\Communication\CommunicationGatewayOperationData;
use App\DTO\Communication\CommunicationGatewayQueryResult;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationGatewayApiException;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Gateway\CommunicationGatewayOperations;
use App\Services\Communication\Contact\CommunicationInboxIdentityProfileMerger;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use Illuminate\Support\Facades\DB;

final readonly class ExecuteCommunicationInboxGatewayAction
{
    public function __construct(
        private CommunicationGatewayOperations $operations,
        private CommunicationPairingStateStore $pairing,
        private CommunicationInboxIdentityProfileMerger $identityProfiles,
    ) {}

    public function command(
        User $actor,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        CommunicationGatewayOperationData $data,
    ): CommunicationGatewayCommandResult {
        if ($type === GatewayCommandType::DisconnectSession) {
            return $this->disconnect($actor, $inbox);
        }

        $entry = $this->operations->enqueue(
            $actor,
            $inbox,
            $type,
            $this->commandPayload($type, $data),
        );

        return CommunicationGatewayCommandResult::fromEntry($entry);
    }

    public function query(
        User $actor,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        CommunicationGatewayOperationData $data,
    ): CommunicationGatewayQueryResult {
        $result = $this->operations->query(
            $actor,
            $inbox,
            $type,
            $this->queryPayload($type, $data),
        );
        if ($type === GatewayQueryType::UserInfo) {
            $this->observeVerifiedNames($inbox, $result);
        }

        return new CommunicationGatewayQueryResult($result);
    }

    public function sessionStatus(
        User $actor,
        CommunicationInbox $inbox,
    ): CommunicationGatewayQueryResult {
        return new CommunicationGatewayQueryResult(
            $this->operations->sessionStatus($actor, $inbox),
        );
    }

    private function disconnect(
        User $actor,
        CommunicationInbox $inbox,
    ): CommunicationGatewayCommandResult {
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

        return CommunicationGatewayCommandResult::fromEntry($entry);
    }

    /** @return array<string, mixed> */
    private function commandPayload(
        GatewayCommandType $type,
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
    ): array {
        if ($type !== GatewayQueryType::ProfilePicture) {
            return $data->parameters;
        }

        $identity = CommunicationIdentity::query()->findOrFail(
            (int) $data->parameters['identity_id'],
        );

        return [
            'user' => $this->identityAddress($identity),
            'preview' => (bool) $data->parameters['preview'],
        ];
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
                ->where('channel', CommunicationChannel::Whatsapp->value)
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
