<?php

namespace App\Services\Integra;

use App\Contracts\IntegraProcuracoesClient;
use App\DTO\Serpro\ProcuracaoLookupRequest;
use App\DTO\Serpro\ProcuracaoLookupResult;

/**
 * Driver fixture (FISCAL_PROFILE=dev): devolve poderes ACTIVE locais sem chamar e-CAC.
 * simulated=false para permitir persistência via TaxProxyPowerService::syncFromApi
 * (mesmo padrão do token fixture do procurador).
 */
final class FixtureIntegraProcuracoesClient implements IntegraProcuracoesClient
{
    /**
     * Códigos oficiais e-CAC usados em elegibilidade.
     *
     * @var list<array{power_code: string, system_code: string}>
     */
    private const FIXTURE_POWERS = [
        ['power_code' => '00146', 'system_code' => 'PGDASD'],
        ['power_code' => '00060', 'system_code' => 'REGIMEAPURACAO'],
        ['power_code' => '00229', 'system_code' => 'DASNSIMEI'],
        ['power_code' => '00103', 'system_code' => 'DCTFWEB'],
        ['power_code' => '00002', 'system_code' => 'SITFIS'],
        ['power_code' => '00004', 'system_code' => 'PAGTOWEB'],
        ['power_code' => '00006', 'system_code' => 'CAIXA_POSTAL'],
    ];

    public function lookup(ProcuracaoLookupRequest $request): ProcuracaoLookupResult
    {
        $validFrom = now()->subYear()->toIso8601String();
        $validTo = now()->addYears(2)->toIso8601String();
        $acceptedAt = now()->toIso8601String();

        $powers = [];
        foreach (self::FIXTURE_POWERS as $row) {
            $powers[] = [
                'power_code' => $row['power_code'],
                'system_code' => $row['system_code'],
                'service_code' => null,
                'valid_from' => $validFrom,
                'valid_to' => $validTo,
                'status' => 'ACTIVE',
                'accepted_at' => $acceptedAt,
            ];
        }

        return new ProcuracaoLookupResult(
            success: true,
            powers: $powers,
            errorCode: null,
            errorMessage: null,
            simulated: false,
            evidenceRef: 'dev-fixture-procuracao:'.$request->clientId,
        );
    }
}
