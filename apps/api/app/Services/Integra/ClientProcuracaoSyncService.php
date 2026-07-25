<?php

namespace App\Services\Integra;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Serpro\SyncClientProcuracaoJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\ClientProcuracaoSync;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\TaxProxyPower;
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
        private readonly OfficeSerproAuthorizationService $authorizations,
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

    public function getOrCreateSnapshot(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
    ): ClientProcuracaoSnapshot {
        if ($client->office_id !== $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório.');
        }

        $snap = ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();

        if ($snap !== null) {
            return $snap;
        }

        return ClientProcuracaoSnapshot::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'environment' => $environment,
            'status' => ClientProcuracaoSyncStatus::Unverified,
            'last_check_result' => 'NEVER_SYNCED',
        ]);
    }

    /**
     * @return array{snapshot: ClientProcuracaoSnapshot, powers: list<TaxProxyPower>}
     */
    public function syncOfficial(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        bool $allowBillableLookup = true,
    ): array {
        if ($client->office_id !== $office->id) {
            throw new RuntimeException('Isolamento de tenant violado.');
        }

        $lock = Cache::lock(
            sprintf('serpro:procuracao-sync:%d:%d:%s', $office->id, $client->id, $environment->value),
            90,
        );
        if (! $lock->get()) {
            throw new RuntimeException('PROCURACAO_SYNC_LOCK_BUSY');
        }

        try {
            $auth = $this->authorizations->getOrCreate($office, $environment);
            $snapshot = $this->getOrCreateSnapshot($office, $client, $environment);

            try {
                $powers = $this->proxyPowers->syncFromApi(
                    $office,
                    $client,
                    $auth,
                    $environment,
                    null,
                    $actorUserId,
                    $allowBillableLookup,
                );
            } catch (RuntimeException $e) {
                $snapshot->status = ClientProcuracaoSyncStatus::Failed;
                $snapshot->last_check_result = 'SYNC_FAILED';
                $snapshot->last_verified_at = now();
                $snapshot->metadata = [
                    'error' => mb_substr($e->getMessage(), 0, 200),
                    'source' => 'official_api',
                ];
                $snapshot->save();

                throw $e;
            }

            $this->projectFromPowers($snapshot, $office, $client, $environment, $auth, $powers);

            $this->audit->record('serpro.procuracao.sync_official', 'SUCCESS', $snapshot, [
                'client_id' => $client->id,
                'status' => $snapshot->status->value,
                'power_count' => count($powers),
            ], $actorUserId, $office->id);

            return ['snapshot' => $snapshot->refresh(), 'powers' => $powers];
        } finally {
            $lock->release();
        }
    }

    public function enqueueSync(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
        bool $automatic = false,
    ): void {
        $snapshot = $this->getOrCreateSnapshot($office, $client, $environment);
        $snapshot->forceFill([
            'status' => ClientProcuracaoSyncStatus::Verifying,
            'last_check_result' => 'QUEUED',
        ])->save();

        SyncClientProcuracaoJob::dispatch(
            officeId: (int) $office->id,
            clientId: (int) $client->id,
            environment: $environment->value,
            actorUserId: $actorUserId,
            correlationId: $correlationId,
            automatic: $automatic,
        );
    }

    /**
     * @return array{fresh: bool, code: string, snapshot: ?ClientProcuracaoSnapshot}
     */
    public function freshness(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório.');
        }

        $snapshot = ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();
        if ($snapshot === null || $snapshot->last_verified_at === null) {
            return ['fresh' => false, 'code' => 'SNAPSHOT_MISSING', 'snapshot' => $snapshot];
        }

        $days = max(1, (int) config('fiscal.procuracao.freshness_days', 7));
        $terminalEvidence = in_array($snapshot->status, [
            ClientProcuracaoSyncStatus::Authorized,
            ClientProcuracaoSyncStatus::Missing,
            ClientProcuracaoSyncStatus::Expired,
        ], true);
        $fresh = $terminalEvidence && $snapshot->last_verified_at->greaterThan(now()->subDays($days));

        return [
            'fresh' => $fresh,
            'code' => $fresh ? 'SNAPSHOT_FRESH' : 'SNAPSHOT_STALE',
            'snapshot' => $snapshot,
        ];
    }

    /**
     * Agenda atualização somente quando ausente/antiga; nunca duplica trabalho fresh.
     *
     * @return array{queued: bool, code: string, snapshot: ?ClientProcuracaoSnapshot}
     */
    public function enqueueRefreshIfNeeded(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        ?int $actorUserId = null,
        ?string $correlationId = null,
    ): array {
        $freshness = $this->freshness($office, $client, $environment);
        if ($freshness['fresh']) {
            return ['queued' => false, 'code' => $freshness['code'], 'snapshot' => $freshness['snapshot']];
        }

        $this->enqueueSync($office, $client, $environment, $actorUserId, $correlationId, automatic: true);

        return ['queued' => true, 'code' => $freshness['code'], 'snapshot' => $freshness['snapshot']];
    }

    /**
     * @param  list<TaxProxyPower>  $powers
     */
    public function projectFromPowers(
        ClientProcuracaoSnapshot $snapshot,
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        OfficeSerproAuthorization $auth,
        array $powers,
    ): ClientProcuracaoSnapshot {
        // Prefer official Integra source; never promote MANUAL as Authorized projection alone.
        $official = array_values(array_filter(
            $powers,
            static fn (TaxProxyPower $p): bool => $p->source === TaxProxyPowerSource::IntegraProcuracoes,
        ));

        $active = [];
        $expired = [];
        $pending = [];

        $pool = $official !== [] ? $official : $powers;

        foreach ($pool as $power) {
            if ($power->source !== TaxProxyPowerSource::IntegraProcuracoes
                && $power->source !== TaxProxyPowerSource::ManualOfficialEvidence
            ) {
                // Manual never drives authorized status for client projection.
                continue;
            }
            // Only official drives Authorized
            if ($power->source !== TaxProxyPowerSource::IntegraProcuracoes) {
                continue;
            }

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
            $snapshot->status = ClientProcuracaoSyncStatus::Authorized;
            $snapshot->valid_from = $validFrom;
            $snapshot->valid_to = $validTo;
            $snapshot->power_codes = array_values(array_unique($codes));
            $snapshot->evidence_ref = $active[0]->evidence_ref;
            $snapshot->last_check_result = 'AUTHORIZED';
        } elseif ($expired !== [] && $active === []) {
            $snapshot->status = ClientProcuracaoSyncStatus::Expired;
            $snapshot->valid_to = $expired[0]->valid_to;
            $snapshot->power_codes = array_values(array_unique(array_map(
                static fn (TaxProxyPower $p) => $p->power_code,
                $expired,
            )));
            $snapshot->evidence_ref = $expired[0]->evidence_ref;
            $snapshot->last_check_result = 'EXPIRED';
        } elseif ($pool === [] || ($official === [] && $powers === [])) {
            $snapshot->status = ClientProcuracaoSyncStatus::Missing;
            $snapshot->power_codes = [];
            $snapshot->last_check_result = 'MISSING';
        } elseif ($official === [] && $powers !== []) {
            // Only manual powers present — never Authorized without official sync
            $snapshot->status = ClientProcuracaoSyncStatus::Unverified;
            $snapshot->power_codes = [];
            $snapshot->last_check_result = 'MANUAL_ONLY';
        } elseif ($pending !== []) {
            // Oficiais pendentes/simulados: não verificada (fail-closed se poder obrigatório)
            $snapshot->status = ClientProcuracaoSyncStatus::Unverified;
            $snapshot->power_codes = array_values(array_unique(array_map(
                static fn (TaxProxyPower $p) => $p->power_code,
                $pending,
            )));
            $snapshot->evidence_ref = $pending[0]->evidence_ref ?? null;
            $snapshot->last_check_result = 'PENDING_OR_SIMULATED';
        } else {
            $snapshot->status = ClientProcuracaoSyncStatus::Missing;
            $snapshot->power_codes = [];
            $snapshot->last_check_result = 'NO_ACTIVE_POWER';
        }

        $snapshot->last_verified_at = CarbonImmutable::now();
        $snapshot->metadata = [
            'source' => 'official_api',
            'author_identity_fingerprint' => substr(hash('sha256', $auth->author_identity), 0, 16),
            'pending_count' => count($pending),
        ];
        $snapshot->save();

        // Dual-write para o modelo canônico de C-1.3 (sem environment; um por client).
        ClientProcuracaoSync::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
            ],
            [
                'status' => $snapshot->status,
                'valid_from' => $snapshot->valid_from,
                'valid_to' => $snapshot->valid_to,
                'last_verified_at' => $snapshot->last_verified_at,
                'evidence_ref' => $snapshot->evidence_ref,
                'powers_summary' => [
                    'power_codes' => $snapshot->power_codes ?? [],
                    'environment' => $environment->value,
                ],
                'last_check_result' => $snapshot->last_check_result,
                'source' => 'official_sync',
            ],
        );

        return $snapshot;
    }

    /**
     * Gate por metadado da operation_key (F-3.3).
     *
     * @param  list<string>  $requiredPowers
     * @return array{allowed: bool, code: ?string, message: ?string, status: ?string}
     */
    public function gateForOperation(
        Office $office,
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

        $snapshot = ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', $environment->value)
            ->first();

        // Fallback para projeção C-1.3 quando snapshot env-scoped ausente.
        if ($snapshot === null) {
            $canonical = ClientProcuracaoSync::query()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->first();
            if ($canonical !== null) {
                $status = $canonical->status;
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
                if ($status === ClientProcuracaoSyncStatus::Authorized && $canonical->isAuthorized()) {
                    if ($canonical->valid_to !== null && $canonical->valid_to->isPast()) {
                        return [
                            'allowed' => false,
                            'code' => 'PROXY_POWER_EXPIRED',
                            'message' => 'Procuração vencida para a operação.',
                            'status' => ClientProcuracaoSyncStatus::Expired->value,
                        ];
                    }

                    return ['allowed' => true, 'code' => null, 'message' => null, 'status' => $status->value];
                }
            }
        }

        $status = $snapshot?->status ?? ClientProcuracaoSyncStatus::Unverified;

        // Snapshot explícito de vencida/ausente bloqueia somente operações com poder obrigatório.
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

        if ($status === ClientProcuracaoSyncStatus::Authorized && $snapshot !== null) {
            if ($snapshot->valid_to !== null && $snapshot->valid_to->isPast()) {
                return [
                    'allowed' => false,
                    'code' => 'PROXY_POWER_EXPIRED',
                    'message' => 'Procuração vencida para a operação.',
                    'status' => ClientProcuracaoSyncStatus::Expired->value,
                ];
            }

            return ['allowed' => true, 'code' => null, 'message' => null, 'status' => $status->value];
        }

        // Unverified / sem snapshot: fallback a TaxProxyPower ACTIVE (legado + sync parcial).
        $hasPower = TaxProxyPower::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('status', TaxProxyPowerStatus::Active->value)
            ->whereIn('power_code', $requiredPowers)
            ->where(function ($q): void {
                $q->whereNull('valid_to')->orWhere('valid_to', '>', now());
            })
            ->exists();

        if ($hasPower) {
            return [
                'allowed' => true,
                'code' => null,
                'message' => null,
                'status' => $status->value,
            ];
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
    public function projectForClient(Office $office, Client $client, ?SerproEnvironment $environment = null): array
    {
        $env = $environment ?? SerproEnvironment::from(
            (string) config('serpro.default_environment', 'TRIAL'),
        );

        $snapshot = ClientProcuracaoSnapshot::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('environment', $env->value)
            ->first();

        if ($snapshot === null) {
            return [
                'status' => ClientProcuracaoSyncStatus::Unverified->value,
                'label' => 'Não verificada',
                'valid_from' => null,
                'valid_to' => null,
                'last_verified_at' => null,
            ];
        }

        return $snapshot->toClientProjection();
    }
}
