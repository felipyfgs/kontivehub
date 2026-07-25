<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\Office;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;

/**
 * Comunicação TEMPLATE_ONLY do PGMEI — mesma infraestrutura, submodule isolado.
 */
final class PgmeiCommunicationService
{
    public const MODULE = 'simples_mei';

    public const SUBMODULE = 'pgmei';

    public const BATCH_LIMIT = PgdasdCommunicationService::BATCH_LIMIT;

    private readonly PgdasdCommunicationService $inner;

    public function __construct(AuditLogger $audit, TenantAuthorization $authorization)
    {
        $this->inner = new PgdasdCommunicationService(
            $audit,
            $authorization,
            self::SUBMODULE,
            'pgmei.communication',
        );
    }

    public function getPreferences(Office $office, Client $client): ClientCommunicationPreference
    {
        return $this->inner->getPreferences($office, $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Office $office, Client $client): array
    {
        return $this->inner->summary($office, $client);
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesForClients(Office $office, array $clientIds): array
    {
        return $this->inner->summariesForClients($office, $clientIds);
    }

    /**
     * @param  array{email_enabled: bool, whatsapp_enabled: bool, automatic_requested: bool, lock_version: int}  $input
     */
    public function updatePreferences(
        Office $office,
        Client $client,
        User $actor,
        array $input,
    ): ClientCommunicationPreference {
        return $this->inner->updatePreferences($office, $client, $actor, $input);
    }

    /**
     * @param  list<int>  $clientIds
     * @return list<ClientCommunicationPreference>
     */
    public function batchSetAutomatic(
        Office $office,
        User $actor,
        array $clientIds,
        bool $automaticRequested,
    ): array {
        return $this->inner->batchSetAutomatic($office, $actor, $clientIds, $automaticRequested);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Office $office, Client $client): array
    {
        return $this->inner->preview($office, $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function tracking(Office $office, Client $client): array
    {
        return $this->inner->tracking($office, $client);
    }

    /**
     * @return array{queued:int, provider_enabled:bool, dispatches:list<array<string, mixed>>}
     */
    public function requestSend(Office $office, Client $client, User $actor, ?string $periodKey = null): array
    {
        return $this->inner->requestSend($office, $client, $actor, $periodKey);
    }

    public function maybeQueueAutomaticAfterConsult(Office $office, Client $client, ?string $periodKey = null): void
    {
        $this->inner->maybeQueueAutomaticAfterConsult($office, $client, $periodKey);
    }
}
