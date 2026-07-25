<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\DctfwebArtifactKind;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Enums\SerproEnvironment;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\MitApuracaoService;

final class MitApuracaoAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly MitApuracaoService $mit,
        private readonly DctfwebDeclarationService $declarations,
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
        return DctfwebCodes::OP_MIT_APURACAO;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->resolvePeriodKey($request);
        $existing = $this->mit->findOrCreate($request->office, $request->client, $periodKey);
        $metadata = is_array($existing->metadata) ? $existing->metadata : [];
        $listMetadata = is_array($metadata['lista_apuracoes_317'] ?? null)
            ? $metadata['lista_apuracoes_317']
            : [];
        $idApuracao = $request->progress['idApuracao']
            ?? $request->progress['id_apuracao']
            ?? $request->context['idApuracao']
            ?? $request->context['id_apuracao']
            ?? ($listMetadata['id_apuracao'] ?? null);

        if (! is_numeric($idApuracao) && $this->caller->resolveEnvironment() === SerproEnvironment::Production) {
            return FiscalAdapterResult::failed(
                'CONSAPURACAO316 exige idApuracao obtido por LISTAAPURACOES317.',
                'MIT_ID_APURACAO_REQUIRED',
                $this->coverage(),
            );
        }

        $payload = is_numeric($idApuracao) ? ['IdApuracao' => (int) $idApuracao] : [];
        $response = $this->callUpstream($request, $payload);

        if (! $response->success) {
            return $this->failedFromResponse($response);
        }

        if (! is_array($response->dados)) {
            return FiscalAdapterResult::failed('Resposta CONSAPURACAO316 sem dados estruturados.', 'MIT_DADOS_INVALID', $this->coverage());
        }
        $bytes = DctfwebIntegraCaller::evidenceBytes($response->dados);
        $mit = $this->mit->projectApuracao(
            $request->office,
            $request->client,
            $periodKey,
            $response->dados,
        );

        $this->declarations->projectArtifact(
            run: $request->run,
            office: $request->office,
            client: $request->client,
            periodKey: $periodKey,
            kind: DctfwebArtifactKind::ApuracaoMit,
            evidenceBytes: $bytes,
            body: $response->dados,
        );

        $situation = $response->simulated
            ? FiscalSituation::Unknown
            : ($mit->situation ?? FiscalSituation::Unknown);

        return $this->successResult(
            situation: $situation,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'module' => 'MIT',
                'operation' => 'CONSULTAR_APURACAO',
                'encerramento_status' => $mit->encerramento_status?->value,
                'dctfweb_transmission_status' => $mit->dctfweb_transmission_status?->value,
                'apuracao' => $mit->metadata['apuracao'] ?? null,
                'simulated' => $response->simulated,
            ],
            coverage: FiscalCoverage::Partial,
        );
    }
}
