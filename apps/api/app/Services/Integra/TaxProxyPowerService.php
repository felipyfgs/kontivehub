<?php

namespace App\Services\Integra;

use App\Contracts\IntegraProcuracoesClient;
use App\DTO\Serpro\ProcuracaoLookupRequest;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\TaxProxyPower;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonImmutable;
use RuntimeException;

final class TaxProxyPowerService
{
    /** Valor histórico apenas; nunca é emitido por sync novo. */
    private const LEGACY_PROVENANCE_SIMULATED = 'SIMULATED';

    public const PROVENANCE_API_VERIFIED = 'API_VERIFIED';

    public const PROVENANCE_MANUAL_PENDING = 'MANUAL_PENDING';

    public const PROVENANCE_MANUAL_APPROVED = 'MANUAL_APPROVED';

    public function __construct(
        private readonly IntegraProcuracoesClient $procuracoes,
        private readonly AuditLogger $audit,
        private readonly ContributorCnpjResolver $contributors,
    ) {}

    /**
     * Importação manual de evidência oficial — permanece PENDING até aprovação explícita.
     */
    public function importManualEvidence(
        Office $office,
        Client $client,
        OfficeSerproAuthorization $auth,
        string $powerCode,
        string $systemCode,
        ?string $serviceCode,
        ?CarbonImmutable $validFrom,
        ?CarbonImmutable $validTo,
        string $evidenceRef,
        ?string $evidenceSha256 = null,
        ?int $actorUserId = null,
        ?SerproEnvironment $environment = null,
    ): TaxProxyPower {
        if ($client->office_id !== $office->id) {
            throw new RuntimeException('Contribuinte não pertence ao escritório.');
        }

        if ($auth->office_id !== $office->id) {
            throw new RuntimeException('Autorização de outro escritório.');
        }

        $env = $environment ?? $auth->environment ?? SerproEnvironment::Trial;
        $contributor = $this->contributors->resolve($client);

        $power = TaxProxyPower::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'power_code' => strtoupper($powerCode),
                'author_identity' => $auth->author_identity,
                'source' => TaxProxyPowerSource::ManualOfficialEvidence->value,
            ],
            [
                'office_serpro_authorization_id' => $auth->id,
                'environment' => $env instanceof SerproEnvironment ? $env->value : (string) $env,
                'contributor_cnpj' => $contributor,
                'system_code' => strtoupper($systemCode),
                'service_code' => $serviceCode !== null ? strtoupper($serviceCode) : null,
                // Nunca ACTIVE sem verificação/aprovação explícita
                'status' => TaxProxyPowerStatus::Pending,
                'provenance' => self::PROVENANCE_MANUAL_PENDING,
                'segregation_class' => SerproDataSegregationClass::HistoricalUnverified->value,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'accepted_at' => null,
                'freshness_checked_at' => null,
                'closed_at' => null,
                'evidence_ref' => mb_substr($evidenceRef, 0, 120),
                'evidence_sha256' => $evidenceSha256,
                'verified_at' => null,
                'last_check_result' => 'MANUAL_IMPORT_PENDING_APPROVAL',
            ],
        );

        $this->audit->record('serpro.proxy_power.import', 'SUCCESS', $power, [
            'power_code' => $power->power_code,
            'client_id' => $client->id,
            'source' => $power->source->value,
            'status' => $power->status->value,
            'provenance' => $power->provenance,
        ], $actorUserId, $office->id);

        return $power->refresh();
    }

    /**
     * Aprovação/verificação explícita de evidência manual (ADMIN).
     */
    public function approveManualEvidence(
        TaxProxyPower $power,
        ?int $actorUserId,
        bool $markAccepted = true,
    ): TaxProxyPower {
        if ($power->source !== TaxProxyPowerSource::ManualOfficialEvidence
            && $power->source !== TaxProxyPowerSource::Import) {
            throw new RuntimeException('Somente evidência manual/import pode ser aprovada por este fluxo.');
        }

        if ($power->status === TaxProxyPowerStatus::Revoked) {
            throw new RuntimeException('Poder revogado não pode ser reativado por aprovação manual.');
        }

        $power->status = TaxProxyPowerStatus::Active;
        $power->provenance = self::PROVENANCE_MANUAL_APPROVED;
        $power->segregation_class = SerproDataSegregationClass::Production->value;
        $power->verified_at = now();
        $power->freshness_checked_at = now();
        if ($markAccepted) {
            $power->accepted_at = $power->accepted_at ?? now();
        }
        $power->closed_at = null;
        $power->last_check_result = 'MANUAL_APPROVED';
        $power->save();

        $this->audit->record('serpro.proxy_power.approve', 'SUCCESS', $power, [
            'power_code' => $power->power_code,
            'client_id' => $power->client_id,
            'actor_user_id' => $actorUserId,
        ], $actorUserId, $power->office_id);

        return $power->refresh();
    }

    /**
     * Sincroniza poderes via adapter Integra-Procurações (fake ou real).
     * Sync completo (sem powerCode) encerra/revoga ausentes.
     * Evidência simulada NUNCA vira ACTIVE.
     *
     * @return list<TaxProxyPower>
     */
    public function syncFromApi(
        Office $office,
        Client $client,
        OfficeSerproAuthorization $auth,
        SerproEnvironment $environment,
        ?string $powerCode = null,
        ?int $actorUserId = null,
        bool $allowBillableLookup = true,
    ): array {
        if ($client->office_id !== $office->id || $auth->office_id !== $office->id) {
            throw new RuntimeException('Isolamento de tenant violado.');
        }

        // Smoke gratuito: bloquear OBTERPROCURACAO41 faturável
        if (! $allowBillableLookup && ! (bool) config('serpro.proxy_powers.allow_billable_lookup_in_free_smoke', false)) {
            throw new RuntimeException(
                'OBTERPROCURACAO41 faturável bloqueado durante free smoke. Use evidência offline/aprovada.'
            );
        }

        $contributor = $this->contributors->resolve($client);

        $result = $this->procuracoes->lookup(new ProcuracaoLookupRequest(
            officeId: $office->id,
            clientId: $client->id,
            environment: $environment->value,
            authorIdentity: $auth->author_identity,
            contributorCnpj: $contributor,
            powerCode: $powerCode,
            correlationId: $this->audit->correlationId(),
        ));

        if (! $result->success) {
            throw new RuntimeException($result->errorMessage ?? 'Falha ao consultar procurações.');
        }
        if ($result->simulated) {
            throw new RuntimeException('Resposta sintética não pode criar ou atualizar poderes.');
        }

        $isFullSync = $powerCode === null || $powerCode === '';
        $seenCodes = [];
        $saved = [];

        foreach ($result->powers as $row) {
            $code = strtoupper((string) ($row['power_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $seenCodes[] = $code;

            $remoteStatus = TaxProxyPowerStatus::tryFrom(strtoupper((string) ($row['status'] ?? 'ACTIVE')))
                ?? TaxProxyPowerStatus::Active;

            // Aceite RFB: PENDING_ACCEPT / AGUARDANDO_ACEITE → inelegível
            $acceptRaw = strtoupper((string) ($row['accept_status'] ?? $row['status_aceite'] ?? ''));
            $needsAccept = in_array($acceptRaw, ['PENDING_ACCEPT', 'AGUARDANDO_ACEITE', 'PENDING', 'NAO_ACEITO'], true);
            $acceptedAt = null;
            if (! $needsAccept && ! $result->simulated) {
                if (! empty($row['accepted_at'])) {
                    $acceptedAt = CarbonImmutable::parse((string) $row['accepted_at']);
                } elseif ($remoteStatus === TaxProxyPowerStatus::Active) {
                    // API real listando ACTIVE implica aceite quando não sinaliza pendência
                    $acceptedAt = now()->toImmutable();
                }
            }

            if ($needsAccept) {
                $status = TaxProxyPowerStatus::Pending;
                $provenance = self::PROVENANCE_API_VERIFIED;
                $segregation = SerproDataSegregationClass::Production->value;
                $lastCheck = 'PENDING_ACCEPT';
                $acceptedAt = null;
            } else {
                $status = $remoteStatus === TaxProxyPowerStatus::Active
                    ? TaxProxyPowerStatus::Active
                    : $remoteStatus;
                $provenance = self::PROVENANCE_API_VERIFIED;
                $segregation = SerproDataSegregationClass::Production->value;
                $lastCheck = 'API_OK';
            }

            $saved[] = TaxProxyPower::query()->updateOrCreate(
                [
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'power_code' => $code,
                    'author_identity' => $auth->author_identity,
                    'source' => TaxProxyPowerSource::IntegraProcuracoes->value,
                ],
                [
                    'office_serpro_authorization_id' => $auth->id,
                    'environment' => $environment->value,
                    'contributor_cnpj' => $contributor,
                    'system_code' => strtoupper((string) ($row['system_code'] ?? '')),
                    'service_code' => isset($row['service_code']) ? strtoupper((string) $row['service_code']) : null,
                    'status' => $status,
                    'provenance' => $provenance,
                    'segregation_class' => $segregation,
                    'valid_from' => ! empty($row['valid_from']) ? CarbonImmutable::parse($row['valid_from']) : null,
                    'valid_to' => ! empty($row['valid_to']) ? CarbonImmutable::parse($row['valid_to']) : null,
                    'accepted_at' => $acceptedAt,
                    'freshness_checked_at' => now(),
                    'closed_at' => null,
                    'evidence_ref' => $result->evidenceRef,
                    'verified_at' => $result->simulated ? null : now(),
                    'last_check_result' => $lastCheck,
                    'metadata' => [
                        'simulated' => $result->simulated,
                        'accept_status' => $acceptRaw !== '' ? $acceptRaw : null,
                    ],
                ],
            );
        }

        // Preserva evidência oficial ainda não mapeada sem conceder poder algum.
        foreach ($result->unmappedSystems as $systemName) {
            $code = 'UNMAPPED_'.strtoupper(substr(hash('sha256', $systemName), 0, 16));
            $seenCodes[] = $code;
            $saved[] = TaxProxyPower::query()->updateOrCreate(
                [
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'power_code' => $code,
                    'author_identity' => $auth->author_identity,
                    'source' => TaxProxyPowerSource::IntegraProcuracoes->value,
                ],
                [
                    'office_serpro_authorization_id' => $auth->id,
                    'environment' => $environment->value,
                    'contributor_cnpj' => $contributor,
                    'system_code' => 'UNMAPPED',
                    'service_code' => null,
                    'status' => TaxProxyPowerStatus::Pending,
                    'provenance' => self::PROVENANCE_API_VERIFIED,
                    'segregation_class' => SerproDataSegregationClass::Production->value,
                    'valid_from' => null,
                    'valid_to' => null,
                    'accepted_at' => null,
                    'freshness_checked_at' => now(),
                    'closed_at' => null,
                    'evidence_ref' => $result->evidenceRef,
                    'verified_at' => now(),
                    'last_check_result' => 'UNMAPPED_OFFICIAL_SYSTEM',
                    'metadata' => ['official_system_name' => mb_substr($systemName, 0, 180)],
                ],
            );
        }

        // Sync completo autenticado: encerra poderes da mesma fonte que sumiram
        $closed = 0;
        if ($isFullSync && ! $result->simulated) {
            $closed = $this->closeMissingPowers(
                office: $office,
                client: $client,
                auth: $auth,
                environment: $environment,
                seenPowerCodes: $seenCodes,
            );
        }

        $this->audit->record('serpro.proxy_power.sync', 'SUCCESS', $auth, [
            'client_id' => $client->id,
            'count' => count($saved),
            'closed' => $closed,
            'simulated' => $result->simulated,
            'full_sync' => $isFullSync,
        ], $actorUserId, $office->id);

        return $saved;
    }

    /**
     * Encerra deterministicamente poderes ACTIVE da fonte API ausentes no sync completo.
     *
     * @param  list<string>  $seenPowerCodes
     */
    public function closeMissingPowers(
        Office $office,
        Client $client,
        OfficeSerproAuthorization $auth,
        SerproEnvironment $environment,
        array $seenPowerCodes,
    ): int {
        $seen = array_map('strtoupper', $seenPowerCodes);

        $query = TaxProxyPower::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('author_identity', $auth->author_identity)
            ->where('source', TaxProxyPowerSource::IntegraProcuracoes->value)
            ->where('environment', $environment->value)
            ->whereIn('status', [
                TaxProxyPowerStatus::Active->value,
                TaxProxyPowerStatus::Pending->value,
            ])
            ->whereNull('closed_at');

        if ($seen !== []) {
            $query->whereNotIn('power_code', $seen);
        }

        $closed = 0;
        foreach ($query->get() as $power) {
            $power->status = TaxProxyPowerStatus::Revoked;
            $power->closed_at = now();
            $power->last_check_result = 'CLOSED_MISSING_FROM_FULL_SYNC';
            $power->save();
            $closed++;
        }

        return $closed;
    }

    public function findUsablePower(
        int $officeId,
        int $clientId,
        string $powerCode,
        string $authorIdentity,
        ?SerproEnvironment $environment = null,
        bool $requireD1 = false,
        bool $requireFresh = true,
        bool $requireAccept = true,
    ): ?TaxProxyPower {
        $query = TaxProxyPower::query()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('power_code', strtoupper($powerCode))
            ->where('author_identity', $authorIdentity)
            ->where('status', TaxProxyPowerStatus::Active->value)
            ->whereNull('closed_at')
            ->orderByDesc('id');

        if ($environment !== null) {
            $query->where(function ($q) use ($environment): void {
                $q->where('environment', $environment->value)
                    ->orWhereNull('environment');
            });
        }

        $power = $query->first();

        if ($power === null) {
            return null;
        }

        // Evidência simulada/local nunca satisfaz chamada ao SERPRO, inclusive
        // no Trial oficial (que deve usar somente resposta do gateway externo).
        if ($power->provenance === self::LEGACY_PROVENANCE_SIMULATED
            || $power->segregation_class === SerproDataSegregationClass::TrialSimulated->value
            || $power->segregation_class === SerproDataSegregationClass::Fake->value
            || $power->segregation_class === SerproDataSegregationClass::Demo->value) {
            return null;
        }

        if (! $power->isCurrentlyValid()) {
            if ($power->valid_to !== null && $power->valid_to->isPast()) {
                $power->status = TaxProxyPowerStatus::Expired;
                $power->save();
            }

            return null;
        }

        $strict = $environment === SerproEnvironment::Production;

        if ($requireAccept && ! $power->isAcceptedByAuthorizee()) {
            // Trial: ACTIVE legado (sem accepted_at) permanece usável; PENDING/simulado não.
            if ($strict || $power->status !== TaxProxyPowerStatus::Active) {
                return null;
            }
            if (in_array($power->provenance, [
                self::LEGACY_PROVENANCE_SIMULATED,
                self::PROVENANCE_MANUAL_PENDING,
            ], true)) {
                return null;
            }
        }

        if ($requireFresh && ! $power->isFresh()) {
            // Produção exige frescor; Trial só bloqueia se freshness_checked_at existir e estiver velho.
            if ($strict) {
                return null;
            }
            if ($power->freshness_checked_at !== null && ! $power->isFresh()) {
                return null;
            }
        }

        if ($requireD1 && ! $power->coversD1()) {
            return null;
        }

        return $power;
    }

    /**
     * Diagnóstico de por que um poder não é usável (para eligibility codes).
     *
     * @return list<string>
     */
    public function diagnoseUnusable(
        int $officeId,
        int $clientId,
        string $powerCode,
        string $authorIdentity,
        ?SerproEnvironment $environment = null,
        bool $requireD1 = false,
    ): array {
        $reasons = [];

        $power = TaxProxyPower::query()
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('power_code', strtoupper($powerCode))
            ->where('author_identity', $authorIdentity)
            ->orderByDesc('id')
            ->first();

        if ($power === null) {
            return ['PROXY_POWER_MISSING'];
        }

        if ($power->status !== TaxProxyPowerStatus::Active || $power->closed_at !== null) {
            if ($power->status === TaxProxyPowerStatus::Pending) {
                if (($power->metadata['accept_status'] ?? null) === 'PENDING_ACCEPT'
                    || $power->last_check_result === 'PENDING_ACCEPT') {
                    $reasons[] = 'PROXY_POWER_NOT_ACCEPTED';
                } elseif ($power->provenance === self::LEGACY_PROVENANCE_SIMULATED
                    || $power->provenance === self::PROVENANCE_MANUAL_PENDING) {
                    $reasons[] = 'PROXY_POWER_MISSING';
                } else {
                    $reasons[] = 'PROXY_POWER_NOT_ACCEPTED';
                }
            } elseif ($power->status === TaxProxyPowerStatus::Expired) {
                $reasons[] = 'PROXY_POWER_EXPIRED';
            } else {
                $reasons[] = 'PROXY_POWER_MISSING';
            }
        }

        if ($power->valid_to !== null && $power->valid_to->isPast()) {
            $reasons[] = 'PROXY_POWER_EXPIRED';
        }

        if (! $power->isAcceptedByAuthorizee()) {
            $reasons[] = 'PROXY_POWER_NOT_ACCEPTED';
        }

        if (! $power->isFresh()) {
            $reasons[] = 'PROXY_POWER_STALE';
        }

        if ($requireD1 && ! $power->coversD1()) {
            $reasons[] = 'PROXY_POWER_D1_MISSING';
        }

        if ($environment !== null
            && $power->environment !== null
            && $power->environment !== $environment->value) {
            $reasons[] = 'ENVIRONMENT_MISMATCH';
        }

        return $reasons === [] ? ['PROXY_POWER_INSUFFICIENT'] : array_values(array_unique($reasons));
    }
}
