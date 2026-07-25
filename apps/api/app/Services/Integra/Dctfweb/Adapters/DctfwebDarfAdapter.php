<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalSituation;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\DctfwebOfficialCodec;
use RuntimeException;

/**
 * Emissão/consulta de documento de arrecadação — NÃO prova pagamento.
 */
final class DctfwebDarfAdapter extends AbstractDctfwebAdapter
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
        return DctfwebCodes::OP_EMITIR_DARF;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->resolvePeriodKey($request);
        $response = $this->callUpstream($request, $this->codec->periodPayload($periodKey));

        if (! $response->success) {
            return $this->failedFromResponse($response);
        }

        if (! is_array($response->dados)) {
            return FiscalAdapterResult::failed('Resposta GERARGUIA31 sem dados estruturados.', 'DCTFWEB_DADOS_INVALID');
        }
        try {
            $bytes = $this->codec->decodePdf($response->dados);
        } catch (RuntimeException $e) {
            return FiscalAdapterResult::failed($e->getMessage(), 'DCTFWEB_DOCUMENT_INVALID');
        }
        $metadata = $this->codec->sanitize($response->dados);
        $darf = $this->declarations->projectDarf(
            run: $request->run,
            office: $request->office,
            client: $request->client,
            periodKey: $periodKey,
            evidenceBytes: $bytes,
            body: $metadata,
            sourceVersion: isset($metadata['versao']) ? (string) $metadata['versao'] : null,
            contentType: 'application/pdf',
        );

        // Situação de atenção: guia emitida, pagamento desconhecido
        $situation = $response->simulated
            ? FiscalSituation::Unknown
            : FiscalSituation::Attention;

        return $this->successResult(
            situation: $situation,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'artifact_kind' => 'DARF',
                'darf_id' => $darf->id,
                'document_number' => $darf->document_number,
                'amount' => $darf->amount,
                'due_at' => $darf->due_at?->toIso8601String(),
                'payment_status' => FiscalPaymentStatus::Unknown->value,
                'payment_confirmed' => false,
                'simulated' => $response->simulated,
            ],
            findings: [[
                'code' => 'DCTFWEB_DARF_EMITIDO',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'Documento de arrecadação gerado',
                'detail' => 'Pagamento permanece desconhecido até confirmação oficial.',
                'situation' => FiscalSituation::Attention->value,
                'creates_pending' => false,
            ]],
            coverage: FiscalCoverage::Full,
            contentType: 'application/pdf',
        );
    }
}
