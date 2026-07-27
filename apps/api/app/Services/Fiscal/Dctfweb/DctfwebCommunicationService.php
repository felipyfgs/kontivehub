<?php

namespace App\Services\Fiscal\Dctfweb;

use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;

/**
 * Comunicação TEMPLATE_ONLY do DCTFWeb — mesma infraestrutura, contexto isolado.
 * module_key=dctfweb, submodule_key=dctfweb; automatic_effective sempre false.
 */
final class DctfwebCommunicationService
{
    public const MODULE = 'dctfweb';

    public const SUBMODULE = 'dctfweb';

    public const BATCH_LIMIT = PgdasdCommunicationService::BATCH_LIMIT;

    private readonly PgdasdCommunicationService $inner;

    public function __construct(AuditLogger $audit, TenantAuthorization $authorization)
    {
        $this->inner = new PgdasdCommunicationService(
            $audit,
            $authorization,
            self::SUBMODULE,
            'dctfweb.communication',
            self::MODULE,
        );
    }

    public function getPreferences(Tenant $tenant, Client $client): ClientCommunicationPreference
    {
        return $this->inner->getPreferences($tenant, $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Tenant $tenant, Client $client): array
    {
        return $this->inner->summary($tenant, $client);
    }

    /**
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function summariesForClients(Tenant $tenant, array $clientIds): array
    {
        return $this->inner->summariesForClients($tenant, $clientIds);
    }

    /**
     * @param  array{email_enabled: bool, whatsapp_enabled: bool, automatic_requested: bool, lock_version: int}  $input
     */
    public function updatePreferences(
        Tenant $tenant,
        Client $client,
        User $actor,
        array $input,
    ): ClientCommunicationPreference {
        return $this->inner->updatePreferences($tenant, $client, $actor, $input);
    }

    /**
     * @param  list<int>  $clientIds
     * @return list<ClientCommunicationPreference>
     */
    public function batchSetAutomatic(
        Tenant $tenant,
        User $actor,
        array $clientIds,
        bool $automaticRequested,
    ): array {
        return $this->inner->batchSetAutomatic($tenant, $actor, $clientIds, $automaticRequested);
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(Tenant $tenant, Client $client): array
    {
        return $this->inner->preview($tenant, $client);
    }

    /**
     * @return array<string, mixed>
     */
    public function tracking(Tenant $tenant, Client $client): array
    {
        return $this->inner->tracking($tenant, $client);
    }

    /**
     * @return array{queued:int, provider_enabled:bool, dispatches:list<array<string, mixed>>}
     */
    public function requestSend(Tenant $tenant, Client $client, User $actor, ?string $periodKey = null): array
    {
        return $this->inner->requestSend($tenant, $client, $actor, $periodKey);
    }

    public function maybeQueueAutomaticAfterConsult(Tenant $tenant, Client $client, ?string $periodKey = null): void
    {
        $this->inner->maybeQueueAutomaticAfterConsult($tenant, $client, $periodKey);
    }
}
