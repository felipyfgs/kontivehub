<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Fiscal\SimplesMei\SimplesMeiOperationDef;
use App\Enums\FiscalGuideEmissionStatus;
use App\Enums\FiscalGuidePaymentStatus;
use App\Models\FiscalGuideStub;
use App\Services\Audit\AuditLogger;

/**
 * Persiste a projeção local de uma emissão DAS concluída pelo adapter real.
 */
final class DasGuideHookService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function persistFromAdapterResult(
        FiscalAdapterRequest $request,
        SimplesMeiOperationDef $def,
        FiscalAdapterResult $result,
    ): FiscalGuideStub {
        $n = $result->normalized ?? [];
        $periodKey = (string) ($n['competence'] ?? $request->competence?->period_key ?? '');

        $stub = FiscalGuideStub::query()->create([
            'office_id' => $request->office->id,
            'client_id' => $request->client->id,
            'run_id' => $request->run->id,
            'system_code' => $def->systemCode,
            'service_code' => $def->serviceCode,
            'operation_code' => $def->operationCode,
            'regime_family' => $def->regimeFamily->value,
            'period_key' => $periodKey,
            'document_number' => $n['document_number'] ?? null,
            'due_date' => $n['due_date'] ?? null,
            'amount' => $n['amount'] ?? null,
            'emission_status' => FiscalGuideEmissionStatus::tryFrom((string) ($n['emission_status'] ?? ''))
                ?? FiscalGuideEmissionStatus::Issued,
            'payment_status' => FiscalGuidePaymentStatus::Unknown,
            'is_external_call' => true,
            'metadata' => [
                'source' => 'integra_response',
                'payment_inferred' => false,
            ],
        ]);

        $this->audit->record(
            action: 'fiscal.simples_mei.das_issued',
            result: 'SUCCESS',
            subject: $stub,
            context: [
                'client_id' => $request->client->id,
                'period_key' => $periodKey,
                'emission_status' => $stub->emission_status->value,
                'payment_status' => FiscalGuidePaymentStatus::Unknown->value,
            ],
            officeId: (int) $request->office->id,
        );

        return $stub;
    }
}
