<?php

namespace App\Services\Integra;

use App\Contracts\IntegraProcuracoesClient;
use App\DTO\Serpro\FiscalIdentity;
use App\DTO\Serpro\ProcuracaoLookupRequest;
use App\DTO\Serpro\ProcuracaoLookupResult;
use App\DTO\Serpro\SerproOperationCommand;
use App\Models\Client;
use App\Models\Office;
use App\Services\Serpro\SerproOperationService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Lookup de procurações via executor central (operation_key oficial).
 */
final class HttpIntegraProcuracoesClient implements IntegraProcuracoesClient
{
    public const OPERATION_KEY = 'procuracoes.obter';

    /**
     * Resolve o executor somente no momento da chamada.
     *
     * A resolução eager cria um ciclo quando authorization=real:
     * SerproOperationService -> ClientProcuracaoSyncService ->
     * TaxProxyPowerService -> HttpIntegraProcuracoesClient -> SerproOperationService.
     */
    private function operations(): SerproOperationService
    {
        return app(SerproOperationService::class);
    }

    public function lookup(ProcuracaoLookupRequest $request): ProcuracaoLookupResult
    {
        $office = Office::query()->withoutGlobalScopes()->find($request->officeId);
        if ($office === null) {
            return new ProcuracaoLookupResult(
                success: false,
                powers: [],
                simulated: false,
                errorCode: 'OFFICE_NOT_FOUND',
                errorMessage: 'Office não encontrado.',
            );
        }

        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($request->clientId)
            ->first();
        if ($client === null) {
            return new ProcuracaoLookupResult(
                success: false,
                powers: [],
                simulated: false,
                errorCode: 'CLIENT_NOT_FOUND',
                errorMessage: 'Cliente não encontrado no escritório informado.',
            );
        }

        try {
            $businessData = $this->buildBusinessData($request);
        } catch (InvalidArgumentException $e) {
            return new ProcuracaoLookupResult(
                success: false,
                powers: [],
                simulated: false,
                errorCode: 'PROCURACAO_IDENTITY_INVALID',
                errorMessage: $e->getMessage(),
            );
        }

        $response = $this->operations()->run(new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: self::OPERATION_KEY,
            businessData: $businessData,
            correlationId: $request->correlationId,
            environment: $request->environment,
            authorIdentityOverride: $request->authorIdentity,
            contributorIdentityOverride: $request->contributorCnpj,
        ));
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        if (! $response->success) {
            return new ProcuracaoLookupResult(
                success: false,
                powers: [],
                simulated: $response->simulated,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
                evidenceRef: $response->requestTag,
            );
        }

        $mapped = $this->mapPowers($response->dados);
        $powers = $mapped['powers'];
        if ($request->powerCode !== null && trim($request->powerCode) !== '') {
            $requestedPower = strtoupper(trim($request->powerCode));
            $powers = array_values(array_filter(
                $powers,
                static fn (array $power): bool => ($power['power_code'] ?? '') === $requestedPower,
            ));
        }

        return new ProcuracaoLookupResult(
            success: true,
            powers: $powers,
            unmappedSystems: $mapped['unmapped_systems'],
            simulated: $response->simulated,
            evidenceRef: $response->requestTag ?? ('PROCURACAO-'.$request->officeId),
        );
    }

    /**
     * Payload oficial OBTERPROCURACAO41: contribuinte outorga ao autor do office.
     *
     * @return array{outorgante: string, tipoOutorgante: string, outorgado: string, tipoOutorgado: string}
     */
    private function buildBusinessData(ProcuracaoLookupRequest $request): array
    {
        $outorgante = FiscalIdentity::fromNumero($request->contributorCnpj);
        $outorgado = FiscalIdentity::fromNumero($request->authorIdentity);

        return [
            'outorgante' => $outorgante->numero,
            'tipoOutorgante' => (string) $outorgante->envelopeTipo(),
            'outorgado' => $outorgado->numero,
            'tipoOutorgado' => (string) $outorgado->envelopeTipo(),
        ];
    }

    /**
     * @return array{powers: list<array<string, mixed>>, unmapped_systems: list<string>}
     */
    private function mapPowers(mixed $dados): array
    {
        if (! is_array($dados)) {
            return ['powers' => [], 'unmapped_systems' => []];
        }

        $rows = $dados['procuracoes'] ?? $dados;
        if (! is_array($rows) || ! array_is_list($rows)) {
            return ['powers' => [], 'unmapped_systems' => []];
        }

        $out = [];
        $unmapped = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $systems = $row['sistemas'] ?? null;
            $declaredCount = $row['nrsistemas'] ?? null;
            if (! is_array($systems)
                || (! is_int($declaredCount) && ! (is_string($declaredCount) && ctype_digit($declaredCount)))
                || (int) $declaredCount !== count($systems)
            ) {
                continue;
            }

            $validTo = $this->parseExpiration($row['dtexpiracao'] ?? null);
            if ($validTo === null) {
                continue;
            }

            foreach ($systems as $system) {
                if (! is_string($system)) {
                    continue;
                }

                $normalizedSystem = $this->normalizeSystemName($system);
                $grants = $this->grantsForSystemName($normalizedSystem);
                if ($grants === []) {
                    $unmapped[$normalizedSystem] = true;
                }
                foreach ($grants as $mapped) {
                    $powerCode = $mapped['power_code'];
                    $current = $out[$powerCode] ?? null;
                    if (is_array($current)
                        && (string) ($current['valid_to'] ?? '') >= $validTo->toIso8601String()
                    ) {
                        continue;
                    }

                    $out[$powerCode] = [
                        'power_code' => $powerCode,
                        'system_code' => $mapped['system_code'],
                        'service_code' => $mapped['service_code'],
                        'valid_from' => null,
                        'valid_to' => $validTo->toIso8601String(),
                        'status' => $validTo->isPast() ? 'EXPIRED' : 'ACTIVE',
                        'raw' => [
                            'dtexpiracao' => (string) $row['dtexpiracao'],
                            'system' => $system,
                        ],
                    ];
                }
            }
        }

        return [
            'powers' => array_values($out),
            'unmapped_systems' => array_keys($unmapped),
        ];
    }

    private function parseExpiration(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || preg_match('/^\d{8}$/', $value) !== 1) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Ymd', $value, 'America/Sao_Paulo');
        } catch (\Throwable) {
            return null;
        }

        if ($date === false || $date->format('Ymd') !== $value) {
            return null;
        }

        return $date->endOfDay();
    }

    private function normalizeSystemName(string $value): string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        return mb_strtoupper(is_string($collapsed) ? $collapsed : '');
    }

    /**
     * @return list<array{power_code: string, system_code: string, service_code: ?string}>
     */
    private function grantsForSystemName(string $normalizedSystem): array
    {
        return app(ProxyPowerMatrixService::class)->grantsForEcacSystemName($normalizedSystem);
    }
}
