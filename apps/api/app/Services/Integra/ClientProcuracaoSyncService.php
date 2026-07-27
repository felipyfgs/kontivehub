<?php

namespace App\Services\Integra;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Sincronização oficial de procurações + projeção de 4 estados (F-3.3).
 * Não há importação/override manual da projeção.
 */
final class ClientProcuracaoSyncService
{
    public function __construct(
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws RuntimeException sempre — projeção não aceita override manual
     */
    public function rejectManualOverride(): never
    {
        throw new RuntimeException(
            'Override/importação manual de procuração é proibido; use sincronização oficial.',
        );
    }

    public function getOrCreateSync(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
    ): ClientProcuracaoSync {
        if ($client->tenant_id !== $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório.');
        }

        $sync = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();

        if ($sync !== null) {
            return $sync;
        }

        return ClientProcuracaoSync::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'environment' => $environment,
            'status' => ClientProcuracaoSyncStatus::Unverified,
            'last_check_result' => 'NEVER_SYNCED',
        ]);
    }

    /**
     * @return array{sync: ClientProcuracaoSync, powers: list<TaxProxyPower>}
     */
    public function syncOfficial(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        bool $allowBillableLookup = true,
    ): array {
        if ($client->tenant_id !== $tenant->id) {
            throw new RuntimeException('Isolamento de tenant violado.');
        }

        $lock = Cache::lock(
            sprintf('serpro:procuracao-sync:%d:%d:%s', $tenant->id, $client->id, $environment->value),
            90,
        );
        if (! $lock->get()) {
            throw new RuntimeException('PROCURACAO_SYNC_LOCK_BUSY');
        }

        try {
            $auth = $this->authorizations->getOrCreate($tenant, $environment);
            $sync = $this->getOrCreateSync($tenant, $client, $environment);

            try {
                $powers = $this->proxyPowers->syncFromApi(
                    $tenant,
                    $client,
                    $auth,
                    $environment,
                    null,
                    $actorUserId,
                    $allowBillableLookup,
                );
            } catch (RuntimeException $e) {
                $sync->status = ClientProcuracaoSyncStatus::Failed;
                $sync->last_check_result = 'SYNC_FAILED';
                $sync->last_verified_at = now();
                $sync->metadata = [
                    'error' => mb_substr($e->getMessage(), 0, 200),
                    'source' => 'official_api',
                ];
                $sync->save();

                throw $e;
            }

            $this->projectFromPowers($sync, $auth, $powers);

            $this->audit->record('serpro.procuracao.sync_official', 'SUCCESS', $sync, [
                'client_id' => $client->id,
                'status' => $sync->status->value,
                'power_count' => count($powers),
            ], $actorUserId, $tenant->id);

            return ['sync' => $sync->refresh(), 'powers' => $powers];
        } finally {
            $lock->release();
        }
    }

    public function enqueueSync(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        bool $automatic = false,
    ): void {
        $sync = $this->getOrCreateSync($tenant, $client, $environment);
        $sync->forceFill([
            'status' => ClientProcuracaoSyncStatus::Verifying,
            'last_check_result' => 'QUEUED',
        ])->save();

        SyncClientProcuracaoJob::dispatch(
            tenantId: (int) $tenant->id,
            clientId: (int) $client->id,
            environment: $environment->value,
            actorUserId: $actorUserId,
            correlationId: $correlationId,
            automatic: $automatic,
        );
    }

    /**
     * @return array{fresh: bool, code: string, sync: ?ClientProcuracaoSync}
     */
    public function freshness(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
    ): array {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório.');
        }

        $sync = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();
        if ($sync === null || $sync->last_verified_at === null) {
            return ['fresh' => false, 'code' => 'SYNC_MISSING', 'sync' => $sync];
        }

        $days = max(1, (int) config('fiscal.procuracao.freshness_days', 7));
        $terminalEvidence = in_array($sync->status, [
            ClientProcuracaoSyncStatus::Authorized,
            ClientProcuracaoSyncStatus::Missing,
            ClientProcuracaoSyncStatus::Expired,
        ], true);
        $fresh = $terminalEvidence && $sync->last_verified_at->greaterThan(now()->subDays($days));

        return [
            'fresh' => $fresh,
            'code' => $fresh ? 'SYNC_FRESH' : 'SYNC_STALE',
            'sync' => $sync,
        ];
    }

    /**
     * Agenda atualização somente quando ausente/antiga; nunca duplica trabalho fresh.
     *
     * @return array{queued: bool, code: string, sync: ?ClientProcuracaoSync}
     */
    public function enqueueRefreshIfNeeded(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
    ): array {
        $freshness = $this->freshness($tenant, $client, $environment);
        if ($freshness['fresh']) {
            return ['queued' => false, 'code' => $freshness['code'], 'sync' => $freshness['sync']];
        }

        $this->enqueueSync($tenant, $client, $environment, $actorUserId, $correlationId, automatic: true);

        return ['queued' => true, 'code' => $freshness['code'], 'sync' => $freshness['sync']];
    }

    /**
     * @param  list<TaxProxyPower>  $powers
     */
    public function projectFromPowers(
        ClientProcuracaoSync $sync,
        TenantSerproAuthorization $auth,
        array $powers,
    ): ClientProcuracaoSync {
        $official = array_values(array_filter(
            $powers,
            static fn (TaxProxyPower $p): bool => $p->source === TaxProxyPowerSource::IntegraProcuracoes,
        ));

        $active = [];
        $expired = [];
        $pending = [];

        $pool = $official;

        foreach ($pool as $power) {
            if ($power->status === TaxProxyPowerStatus::Active && $power->isCurrentlyValid()) {
                $active[] = $power;
            } elseif (
                $power->status === TaxProxyPowerStatus::Expired
                || ($power->valid_to !== null && $power->valid_to->isPast())
            ) {
                $expired[] = $power;
            } else {
                $pending[] = $power;
            }
        }

        if ($active !== []) {
            $validTo = null;
            $validFrom = null;
            $codes = [];
            foreach ($active as $p) {
                $codes[] = $p->power_code;
                if ($p->valid_to !== null && ($validTo === null || $p->valid_to->lt($validTo))) {
                    $validTo = $p->valid_to;
                }
                if ($p->valid_from !== null && ($validFrom === null || $p->valid_from->gt($validFrom))) {
                    $validFrom = $p->valid_from;
                }
            }
            $sync->status = ClientProcuracaoSyncStatus::Authorized;
            $sync->valid_from = $validFrom;
            $sync->valid_to = $validTo;
            $sync->power_codes = array_values(array_unique($codes));
            $sync->evidence_ref = $active[0]->evidence_ref;
            $sync->last_check_result = 'AUTHORIZED';
        } elseif ($expired !== [] && $active === []) {
            $sync->status = ClientProcuracaoSyncStatus::Expired;
            $sync->valid_to = $expired[0]->valid_to;
            $sync->power_codes = array_values(array_unique(array_map(
                static fn (TaxProxyPower $p) => $p->power_code,
                $expired,
            )));
            $sync->evidence_ref = $expired[0]->evidence_ref;
            $sync->last_check_result = 'EXPIRED';
        } elseif ($pool === []) {
            $sync->status = ClientProcuracaoSyncStatus::Missing;
            $sync->power_codes = [];
            $sync->last_check_result = 'MISSING';
        } elseif ($pending !== []) {
            // Poderes oficiais pendentes: não verificada (fail-closed se poder obrigatório).
            $sync->status = ClientProcuracaoSyncStatus::Unverified;
            $sync->power_codes = array_values(array_unique(array_map(
                static fn (TaxProxyPower $p) => $p->power_code,
                $pending,
            )));
            $sync->evidence_ref = $pending[0]->evidence_ref ?? null;
            $sync->last_check_result = 'PENDING_OR_SIMULATED';
        } else {
            $sync->status = ClientProcuracaoSyncStatus::Missing;
            $sync->power_codes = [];
            $sync->last_check_result = 'NO_ACTIVE_POWER';
        }

        $sync->last_verified_at = CarbonImmutable::now();
        $sync->metadata = [
            'source' => 'official_api',
            'author_identity_fingerprint' => substr(hash('sha256', $auth->author_identity), 0, 16),
            'pending_count' => count($pending),
        ];
        $sync->save();

        return $sync;
    }

    /**
     * Gate por metadado da operation_key (F-3.3).
     *
     * @param  list<string>  $requiredPowers
     * @return array{allowed: bool, code: ?string, message: ?string, status: ?string}
     */
    public function gateForOperation(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        array $requiredPowers,
        string $proxyRule = 'NOT_APPLICABLE',
    ): array {
        if (in_array($proxyRule, ['NOT_APPLICABLE', 'EVENT_DEPENDENT'], true)) {
            return ['allowed' => true, 'code' => null, 'message' => null, 'status' => null];
        }

        $requiredPowers = array_values(array_filter(array_map(
            static fn ($p) => strtoupper(trim((string) $p)),
            $requiredPowers,
        )));

        if ($requiredPowers === []) {
            return ['allowed' => true, 'code' => null, 'message' => null, 'status' => null];
        }

        $sync = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();

        $status = $sync?->status ?? ClientProcuracaoSyncStatus::Unverified;

        // Sync explícito de vencida/ausente bloqueia somente operações com poder obrigatório.
        if ($status === ClientProcuracaoSyncStatus::Expired) {
            return [
                'allowed' => false,
                'code' => 'PROXY_POWER_EXPIRED',
                'message' => 'Procuração vencida para a operação.',
                'status' => $status->value,
            ];
        }

        if ($status === ClientProcuracaoSyncStatus::Missing) {
            return [
                'allowed' => false,
                'code' => 'PROXY_POWER_MISSING',
                'message' => 'Cliente sem procuração para a operação exigida.',
                'status' => $status->value,
            ];
        }

        if ($status === ClientProcuracaoSyncStatus::Authorized && $sync !== null) {
            if ($sync->valid_to !== null && $sync->valid_to->isPast()) {
                return [
                    'allowed' => false,
                    'code' => 'PROXY_POWER_EXPIRED',
                    'message' => 'Procuração vencida para a operação.',
                    'status' => ClientProcuracaoSyncStatus::Expired->value,
                ];
            }

            return ['allowed' => true, 'code' => null, 'message' => null, 'status' => $status->value];
        }

        return [
            'allowed' => false,
            'code' => 'PROXY_POWER_UNVERIFIED',
            'message' => 'Procuração não verificada; sincronize antes da operação.',
            'status' => $status->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function projectForClient(Tenant $tenant, Client $client, ?SerproEnvironment $environment = null): array
    {
        $env = $environment ?? SerproEnvironment::from(
            (string) config('serpro.default_environment', 'TRIAL'),
        );

        $sync = ClientProcuracaoSync::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('environment', $env->value)
            ->first();

        if ($sync === null) {
            return [
                'status' => ClientProcuracaoSyncStatus::Unverified->value,
                'label' => 'Não verificada',
                'valid_from' => null,
                'valid_to' => null,
                'last_verified_at' => null,
            ];
        }

        return $sync->toClientProjection();
    }
}
