<?php

namespace App\Services\Integra;

use App\Contracts\EnsuresClientProcuracaoForConsult;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use RuntimeException;
use Throwable;

/**
 * Garante evidência de procuração usável antes de consulta Integra que exige poder e-CAC.
 * Fluxo: local → sync oficial (Integra/fixture) se necessário → recheck.
 */
final class EnsureClientProcuracaoForConsult implements EnsuresClientProcuracaoForConsult
{
    public function __construct(
        private readonly TaxProxyPowerService $proxyPowers,
        private readonly ClientProcuracaoSyncService $procuracoes,
        private readonly TenantSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $requiredPowers  Códigos oficiais, com semântica ANY-of
     * @return array{ok: bool, synced: bool, code: ?string, message: ?string}
     */
    public function ensure(
        Tenant $tenant,
        Client $client,
        SerproEnvironment $environment,
        array $requiredPowers,
        ?int $actorUserId = null,
    ): array {
        if ($client->tenant_id !== $tenant->id) {
            return [
                'ok' => false,
                'synced' => false,
                'code' => 'CONTRIBUTOR_CROSS_TENANT',
                'message' => 'Cliente não pertence ao escritório.',
            ];
        }

        $codes = $this->normalizePowers($requiredPowers);
        if ($codes === []) {
            return ['ok' => true, 'synced' => false, 'code' => null, 'message' => null];
        }

        $auth = $this->authorizations->getOrCreate($tenant, $environment);
        $authorIdentity = trim((string) ($auth->author_identity ?? ''));
        if ($authorIdentity === '') {
            return [
                'ok' => false,
                'synced' => false,
                'code' => 'AUTHOR_IDENTITY_MISSING',
                'message' => 'Autor do Pedido não configurado.',
            ];
        }

        if ($this->hasAnyUsable($tenant->id, $client->id, $codes, $authorIdentity, $environment)) {
            return ['ok' => true, 'synced' => false, 'code' => null, 'message' => null];
        }

        try {
            $this->procuracoes->syncOfficial(
                $tenant,
                $client,
                $environment,
                $actorUserId,
                allowBillableLookup: true,
            );
        } catch (Throwable $e) {
            $code = $e instanceof RuntimeException && $e->getMessage() === 'PROCURACAO_SYNC_LOCK_BUSY'
                ? 'PROCURACAO_SYNC_BUSY'
                : 'PROCURACAO_SYNC_FAILED';

            $this->audit->record('tenant.procuracao.ensure', 'FAILED', null, [
                'client_id' => $client->id,
                'environment' => $environment->value,
                'code' => $code,
                'message' => mb_substr($e->getMessage(), 0, 200),
            ], $actorUserId, $tenant->id);

            return [
                'ok' => false,
                'synced' => false,
                'code' => $code,
                'message' => 'Falha ao sincronizar procurações: '.$e->getMessage(),
            ];
        }

        if ($this->hasAnyUsable($tenant->id, $client->id, $codes, $authorIdentity, $environment)) {
            $this->audit->record('tenant.procuracao.ensure', 'SUCCESS', null, [
                'client_id' => $client->id,
                'environment' => $environment->value,
                'synced' => true,
            ], $actorUserId, $tenant->id);

            return ['ok' => true, 'synced' => true, 'code' => null, 'message' => null];
        }

        $diag = [];
        foreach ($codes as $powerCode) {
            foreach ($this->proxyPowers->diagnoseUnusable(
                $tenant->id,
                $client->id,
                $powerCode,
                $authorIdentity,
                $environment,
            ) as $reason) {
                $diag[] = $reason;
            }
        }
        $code = $diag[0] ?? 'PROXY_POWER_MISSING';

        $this->audit->record('tenant.procuracao.ensure', 'FAILED', null, [
            'client_id' => $client->id,
            'environment' => $environment->value,
            'synced' => true,
            'code' => $code,
            'required_powers' => $codes,
        ], $actorUserId, $tenant->id);

        return [
            'ok' => false,
            'synced' => true,
            'code' => $code,
            'message' => 'Elegibilidade Integra negada: '.$code,
        ];
    }

    /**
     * @param  list<string>  $requiredPowers
     * @return list<string>
     */
    private function normalizePowers(array $requiredPowers): array
    {
        $out = [];
        foreach ($requiredPowers as $raw) {
            $code = strtoupper(trim((string) $raw));
            if ($code !== '') {
                $out[$code] = $code;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<string>  $codes
     */
    private function hasAnyUsable(
        int $tenantId,
        int $clientId,
        array $codes,
        string $authorIdentity,
        SerproEnvironment $environment,
    ): bool {
        foreach ($codes as $powerCode) {
            $power = $this->proxyPowers->findUsablePower(
                tenantId: $tenantId,
                clientId: $clientId,
                powerCode: $powerCode,
                authorIdentity: $authorIdentity,
                environment: $environment,
                requireFresh: true,
                requireAccept: true,
            );
            if ($power !== null) {
                return true;
            }
        }

        return false;
    }
}
