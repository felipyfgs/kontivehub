<?php

namespace App\Services\Integra;

use App\Contracts\EnsuresClientProcuracaoForConsult;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
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
        private readonly OfficeSerproAuthorizationService $authorizations,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<string>  $requiredPowers  ANY-of (aliases de catálogo e/ou códigos oficiais)
     * @return array{ok: bool, synced: bool, code: ?string, message: ?string}
     */
    public function ensure(
        Office $office,
        Client $client,
        SerproEnvironment $environment,
        array $requiredPowers,
        ?int $actorUserId = null,
    ): array {
        if ($client->office_id !== $office->id) {
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

        $auth = $this->authorizations->getOrCreate($office, $environment);
        $authorIdentity = trim((string) ($auth->author_identity ?? ''));
        if ($authorIdentity === '') {
            return [
                'ok' => false,
                'synced' => false,
                'code' => 'AUTHOR_IDENTITY_MISSING',
                'message' => 'Autor do Pedido não configurado.',
            ];
        }

        if ($this->hasAnyUsable($office->id, $client->id, $codes, $authorIdentity, $environment)) {
            return ['ok' => true, 'synced' => false, 'code' => null, 'message' => null];
        }

        try {
            $this->procuracoes->syncOfficial(
                $office,
                $client,
                $environment,
                $actorUserId,
                allowBillableLookup: true,
            );
        } catch (Throwable $e) {
            $code = $e instanceof RuntimeException && $e->getMessage() === 'PROCURACAO_SYNC_LOCK_BUSY'
                ? 'PROCURACAO_SYNC_BUSY'
                : 'PROCURACAO_SYNC_FAILED';

            $this->audit->record('office.procuracao.ensure', 'FAILED', null, [
                'client_id' => $client->id,
                'environment' => $environment->value,
                'code' => $code,
                'message' => mb_substr($e->getMessage(), 0, 200),
            ], $actorUserId, $office->id);

            return [
                'ok' => false,
                'synced' => false,
                'code' => $code,
                'message' => 'Falha ao sincronizar procurações: '.$e->getMessage(),
            ];
        }

        if ($this->hasAnyUsable($office->id, $client->id, $codes, $authorIdentity, $environment)) {
            $this->audit->record('office.procuracao.ensure', 'SUCCESS', null, [
                'client_id' => $client->id,
                'environment' => $environment->value,
                'synced' => true,
            ], $actorUserId, $office->id);

            return ['ok' => true, 'synced' => true, 'code' => null, 'message' => null];
        }

        $diag = [];
        foreach ($codes as $powerCode) {
            foreach ($this->proxyPowers->diagnoseUnusable(
                $office->id,
                $client->id,
                $powerCode,
                $authorIdentity,
                $environment,
            ) as $reason) {
                $diag[] = $reason;
            }
        }
        $code = $diag[0] ?? 'PROXY_POWER_MISSING';

        $this->audit->record('office.procuracao.ensure', 'FAILED', null, [
            'client_id' => $client->id,
            'environment' => $environment->value,
            'synced' => true,
            'code' => $code,
            'required_powers' => $codes,
        ], $actorUserId, $office->id);

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
            foreach ($this->expandAliases(strtoupper(trim((string) $raw))) as $code) {
                if ($code !== '') {
                    $out[$code] = $code;
                }
            }
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function expandAliases(string $code): array
    {
        return match ($code) {
            'PGDASD' => ['PGDASD', '00146'],
            '00146' => ['00146', 'PGDASD'],
            'REGIME_APURACAO', 'REGIMEAPURACAO' => ['REGIME_APURACAO', '00060'],
            '00060' => ['00060', 'REGIME_APURACAO'],
            default => [$code],
        };
    }

    /**
     * @param  list<string>  $codes
     */
    private function hasAnyUsable(
        int $officeId,
        int $clientId,
        array $codes,
        string $authorIdentity,
        SerproEnvironment $environment,
    ): bool {
        foreach ($codes as $powerCode) {
            $power = $this->proxyPowers->findUsablePower(
                officeId: $officeId,
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
