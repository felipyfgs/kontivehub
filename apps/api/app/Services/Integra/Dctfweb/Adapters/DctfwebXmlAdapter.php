<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\DctfwebArtifactKind;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalSituation;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\DctfwebOfficialCodec;
use RuntimeException;

final class DctfwebXmlAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly DctfwebDeclarationService $declarations,
        private readonly DctfwebOfficialCodec $codec,
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
        return DctfwebCodes::OP_CONSULTAR_XML;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->resolvePeriodKey($request);
        $response = $this->callUpstream($request, $this->codec->periodPayload($periodKey));

        if (! $response->success) {
            return $this->failedFromResponse($response);
        }

        if (! is_array($response->dados)) {
            return FiscalAdapterResult::failed('Resposta CONSXMLDECLARACAO38 sem dados estruturados.', 'DCTFWEB_DADOS_INVALID');
        }
        try {
            $bytes = $this->codec->decodeXml($response->dados);
        } catch (RuntimeException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'DCTFWEB_DOCUMENT_INVALID');
        }
        $contentType = 'application/xml';
        $metadata = $this->codec->sanitize($response->dados);

        $projected = $this->declarations->projectArtifact(
            run: $request->run,
            office: $request->office,
            client: $request->client,
            periodKey: $periodKey,
            kind: DctfwebArtifactKind::Xml,
            evidenceBytes: $bytes,
            body: $metadata,
            contentType: $contentType,
            sourceVersion: isset($metadata['versao']) ? (string) $metadata['versao'] : null,
        );

        $situation = $response->simulated
            ? FiscalSituation::Unknown
            : FiscalSituation::UpToDate;

        return $this->successResult(
            situation: $situation,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'artifact_kind' => 'XML',
                'retification' => $projected['retification'],
                'evidence_version' => $projected['version']->version,
                'content_sha256' => $projected['version']->content_sha256,
                'simulated' => $response->simulated,
            ],
            findings: $this->retificationFinding($projected['retification']),
            coverage: FiscalCoverage::Full,
            contentType: $contentType,
        );
    }
}
