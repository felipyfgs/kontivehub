<?php

namespace App\Services\Communication\Gateway;

use App\Contracts\CommunicationTransport;
use App\DTO\Communication\GatewayQueryData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\GatewayQueryType;
use App\Enums\Communication\InboxStatus;
use App\Enums\TenantPermission;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use Illuminate\Support\Str;

/**
 * Boundary tenant-aware para as futuras APIs de ações e controles da inbox.
 * A sessão sempre vem da inbox autorizada; office_id nunca é aceito do caller.
 */
final readonly class CommunicationGatewayOperations
{
    public function __construct(
        private CommunicationAccess $access,
        private CommunicationAvailability $availability,
        private CommunicationGatewayOperationPolicy $policy,
        private CommunicationOutboxService $outbox,
        private CommunicationTransport $transport,
        private CommunicationPairingStateStore $pairing,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(
        User $actor,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        array $payload,
        ?CommunicationMessage $message = null,
        ?string $commandId = null,
    ): CommunicationOutboxEntry {
        $this->authorizeCommand($actor, $inbox, $type, $payload);

        return $this->outbox->enqueue($inbox, $type, $payload, $message, $commandId);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function query(
        User $actor,
        CommunicationInbox $inbox,
        GatewayQueryType $type,
        array $payload = [],
        ?string $queryId = null,
    ): array {
        $this->access->assertManage($actor, $inbox);
        $this->availability->assertEnabled($inbox, true);

        return $this->transport->query(new GatewayQueryData(
            queryId: $queryId ?? 'query-'.strtolower((string) Str::ulid()),
            sessionId: (string) $inbox->session_id,
            type: $type,
            payload: $payload,
        ));
    }

    /** @return array<string, mixed> */
    public function sessionStatus(User $actor, CommunicationInbox $inbox): array
    {
        $this->access->assertManage($actor, $inbox);
        $this->availability->assertGatewayAvailable();
        $status = $this->transport->sessionStatus((string) $inbox->session_id);
        $canonical = InboxStatus::normalize($status['status'] ?? null);
        if ($canonical === null) {
            throw new \RuntimeException('Status de sessão inválido.');
        }
        $pairing = $this->pairing->get((int) $inbox->id);

        return [
            ...$status,
            'status' => $canonical->value,
            'pairing' => $pairing,
            'pairing_expires_at' => $pairing['expires_at'] ?? null,
            'pairing_error_code' => $pairing['error_code'] ?? null,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function authorizeCommand(
        User $actor,
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        array $payload,
    ): void {
        if ($this->policy->permissionFor($type, $payload) === TenantPermission::CommunicationReply) {
            $this->access->assertReply($actor, $inbox);

            return;
        }

        $this->access->assertManage($actor, $inbox);
    }
}
