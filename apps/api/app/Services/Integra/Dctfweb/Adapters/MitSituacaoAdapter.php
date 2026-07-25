<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\DctfwebArtifactKind;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalSituation;
use App\Enums\MitEncerramentoStatus;
use App\Enums\SerproEnvironment;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\MitApuracaoService;

/**
 * Situação MIT — não infere sucesso de transmissão DCTFWeb.
 */
final class MitSituacaoAdapter extends AbstractDctfwebAdapter
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
        return DctfwebCodes::OP_MIT_SITUACAO;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->resolvePeriodKey($request);
        $protocol = $request->progress['protocoloEncerramento']
            ?? $request->progress['protocolo_encerramento']
            ?? $request->context['protocoloEncerramento']
            ?? $request->context['protocolo_encerramento']
            ?? null;
        if ((! is_string($protocol) || trim($protocol) === '')
            && $this->caller->resolveEnvironment() === SerproEnvironment::Production
        ) {
            return FiscalAdapterResult::failed(
                'SITUACAOENC315 exige protocoloEncerramento retornado pelo encerramento MIT.',
                'MIT_PROTOCOL_REQUIRED',
                $this->coverage(),
            );
        }

        $payload = is_string($protocol) && trim($protocol) !== ''
            ? ['protocoloEncerramento' => trim($protocol)]
            : [];
        $response = $this->callUpstream($request, $payload);

        if (! $response->success) {
            return $this->failedFromResponse($response);
        }

        if (! is_array($response->dados)) {
            return FiscalAdapterResult::failed('Resposta SITUACAOENC315 sem dados estruturados.', 'MIT_DADOS_INVALID', $this->coverage());
        }
        $bytes = DctfwebIntegraCaller::evidenceBytes($response->dados);
        $mit = $this->mit->projectSituacao(
            $request->office,
            $request->client,
            $periodKey,
            $response->dados,
        );

        // Garante declaração existe para espelho (sem promover transmissão)
        $this->declarations->findOrCreate($request->office, $request->client, $periodKey);
        $this->mit->syncDctfwebMirror($mit);
        $mit = $mit->fresh();

        // Persiste evidência MIT como versão em declaração “irmã” se houver
        $decl = $this->declarations->findOrCreate($request->office, $request->client, $periodKey);
        $this->declarations->projectArtifact(
            run: $request->run,
            office: $request->office,
            client: $request->client,
            periodKey: $periodKey,
            kind: DctfwebArtifactKind::SituacaoMit,
            evidenceBytes: $bytes,
            body: $response->dados,
        );

        $findings = [];
        if (
            $mit->encerramento_status === MitEncerramentoStatus::Encerrado
            && ! $mit->dctfweb_transmission_status?->isConfirmed()
        ) {
            $findings[] = [
                'code' => 'MIT_ENCERRADO_SEM_DCTFWEB',
                'severity' => FiscalFindingSeverity::Medium->value,
                'title' => 'MIT encerrado sem transmissão DCTFWeb confirmada',
                'detail' => 'Etapa MIT concluída; transmissão DCTFWeb permanece '
                    .($mit->dctfweb_transmission_status?->value ?? 'UNKNOWN').'.',
                'situation' => FiscalSituation::Pending->value,
                'creates_pending' => true,
            ];
        }

        $situation = $response->simulated
            ? FiscalSituation::Unknown
            : ($mit->situation ?? FiscalSituation::Unknown);

        return $this->successResult(
            situation: $situation,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'module' => 'MIT',
                'encerramento_status' => $mit->encerramento_status?->value,
                'situacao_status' => $mit->situacao_status,
                'dctfweb_transmission_status' => $mit->dctfweb_transmission_status?->value,
                'stages' => [
                    'mit_encerramento' => $mit->encerramento_status?->value,
                    'dctfweb_transmissao' => $mit->dctfweb_transmission_status?->value,
                ],
                'declaration_id' => $decl->id,
                'simulated' => $response->simulated,
            ],
            findings: $findings,
            coverage: FiscalCoverage::Partial,
        );
    }
}
