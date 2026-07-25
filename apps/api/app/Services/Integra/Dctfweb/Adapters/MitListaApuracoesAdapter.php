<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Integra\MitListaApuracoesRequest;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\MitApuracaoService;
use App\Services\Integra\Dctfweb\MitListaApuracoesCodec;
use InvalidArgumentException;

/** Consulta não mutante MIT/LISTAAPURACOES317, sem criar evidência documental. */
final class MitListaApuracoesAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly MitApuracaoService $mit,
        private readonly MitListaApuracoesCodec $codec,
    ) {
        parent::__construct($caller, $competences);
    }

    public function systemCode(): string
    {
        return DctfwebCodes::SYSTEM_MIT;
    }

    public function serviceCode(): string
    {
        return DctfwebCodes::SERVICE_MIT;
    }

    public function operationCode(): string
    {
        return DctfwebCodes::OP_MIT_LISTAR_APURACOES;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        try {
            $filters = MitListaApuracoesRequest::fromArray(
                is_array($request->progress['mit_lista_apuracoes'] ?? null)
                    ? $request->progress['mit_lista_apuracoes']
                    : [],
            );
        } catch (InvalidArgumentException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'MIT_LISTA_APURACOES_FILTER_INVALID', $this->coverage());
        }

        $response = $this->caller->callMitListaApuracoes($request, $filters);
        if (! $response->success) {
            return $this->failedFromResponse($response);
        }

        try {
            $items = $this->codec->decode(is_array($response->dados) ? $response->dados : []);
        } catch (InvalidArgumentException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'MIT_LISTA_APURACOES_RESPONSE_INVALID', $this->coverage());
        }

        $this->mit->projectListaApuracoes($request->office, $request->client, $items);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::Unknown,
            coverage: $this->coverage(),
            // 317 retorna lista de dados, não documento. Nunca cria artefato/cofre.
            evidenceBytes: null,
            normalized: [
                'module' => 'MIT',
                'operation_key' => DctfwebCodes::OPERATION_KEY_MIT_LISTA_APURACOES,
                'filters' => $filters->toPayload(),
                'apuracoes' => $items,
                'simulated' => $response->simulated,
            ],
            itemsProcessed: count($items),
            pagesProcessed: 1,
        );
    }
}
