<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\DctfwebCategory;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Services\Fiscal\Dctfweb\DctfwebConsReciboCodec;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use App\Services\Fiscal\Dctfweb\DctfwebPostConsultService;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;

/**
 * Monitor agendado DCTFWeb: uma única chamada CONSRECIBO32 por cliente/PA congelado.
 * Sem fallback automático para declaração completa ou outras operações faturáveis.
 */
final class DctfwebMonitorAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly DctfwebConsReciboCodec $codec,
        private readonly DctfwebPostConsultService $postConsult,
    ) {
        parent::__construct($caller, $competences);
    }

    public function systemCode(): string
    {
        return DctfwebCodes::SYSTEM_DCTFWEB;
    }

    public function serviceCode(): string
    {
        return DctfwebCodes::SERVICE_DCTFWEB;
    }

    public function operationCode(): string
    {
        return DctfwebCodes::OP_MONITOR;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->postConsult->resolveExpectedPeriodKey($request);
        $pa = DctfwebPeriod::parse($periodKey);
        $payload = $this->codec->buildPayload(
            DctfwebPeriod::toAnoPa($pa),
            DctfwebPeriod::toMesPa($pa),
            DctfwebCategory::default(),
        );

        // Uma única operação: CONSULTAR_RECIBO (dctfweb.consrecibo / CONSRECIBO32).
        $response = $this->caller->call(
            request: $request,
            solutionCode: DctfwebCodes::SYSTEM_DCTFWEB,
            serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
            operationCode: DctfwebCodes::OP_CONSULTAR_RECIBO,
            payload: $payload,
        );

        if (! $response->success) {
            $failed = $this->failedFromResponse($response);
            $pack = $this->postConsult->handle($request, $response, $failed);

            return $pack['result'];
        }

        $base = $this->successResult(
            situation: FiscalSituation::Unknown,
            evidenceBytes: '{}',
            normalized: [
                'period_key' => $periodKey,
                'operation_key' => DctfwebCodes::OPERATION_KEY_CONSRECIBO,
                'payload' => $payload,
                'directed_event' => true,
            ],
            coverage: FiscalCoverage::Full,
        );

        $pack = $this->postConsult->handle($request, $response, $base);

        return $pack['result'];
    }
}
