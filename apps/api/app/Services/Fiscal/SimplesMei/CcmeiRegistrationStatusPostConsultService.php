<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use Illuminate\Support\Facades\Log;
use Throwable;

final class CcmeiRegistrationStatusPostConsultService
{
    public const OPERATION_KEY = 'ccmei.ccmeisitcadastral';

    public function __construct(private readonly CcmeiRegistrationStatusProjector $projector) {}

    /** @return array{result:FiscalAdapterResult} */
    public function handle(FiscalAdapterRequest $request, IntegraResponse $response, FiscalAdapterResult $result, string $operationKey): array
    {
        if ($operationKey !== self::OPERATION_KEY || ! $response->success || $result->result->value !== 'SUCCESS') {
            return ['result' => $result];
        }
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        if ($response->hasSimulatedSource()) {
            $normalized['ccmei_registration_status'] = [
                'operation_key' => self::OPERATION_KEY,
                'promoted' => false,
                'reason' => 'SIMULATED_SOURCE_REJECTED',
            ];

            return ['result' => $this->withNormalized($result, $normalized)];
        }
        try {
            $projected = $this->projector->project($request->office, $request->client, [
                'status' => (string) ($normalized['status'] ?? ''),
                'enquadrado_mei' => (bool) ($normalized['enquadrado_mei'] ?? false),
                'situation' => (string) ($normalized['situation'] ?? ''), 'count' => (int) ($normalized['count'] ?? 0),
            ], $request->run->id, $this->provenance($response));
            $normalized['ccmei_registration_status'] = ['operation_key' => self::OPERATION_KEY, 'promoted' => true,
                'observation_id' => $projected['observation']->id, ...$projected['projection']->toPublicArray()];
        } catch (Throwable) {
            Log::warning('ccmei.registration_status_projection_failed', ['operation_key' => self::OPERATION_KEY,
                'office_id' => $request->office->id, 'client_id' => $request->client->id, 'reason' => 'PROJECTION_FAILED']);
            $normalized['ccmei_registration_status'] = ['operation_key' => self::OPERATION_KEY, 'promoted' => false, 'reason' => 'PROJECTION_FAILED'];
        }

        return ['result' => $this->withNormalized($result, $normalized)];
    }

    private function provenance(IntegraResponse $response): string
    {
        return match ($response->sourceProvenance) {
            FiscalSourceProvenance::SerproReal->value => FiscalSourceProvenance::SerproReal->value,
            FiscalSourceProvenance::SerproTrial->value => FiscalSourceProvenance::SerproTrial->value,
            default => FiscalSourceProvenance::Unverified->value,
        };
    }

    private function withNormalized(FiscalAdapterResult $result, array $normalized): FiscalAdapterResult
    {
        return new FiscalAdapterResult(result: $result->result, situation: $result->situation, coverage: $result->coverage,
            evidenceBytes: $result->evidenceBytes, evidenceContentType: $result->evidenceContentType, sourceVersion: $result->sourceVersion,
            normalized: $normalized, findings: $result->findings, itemsProcessed: $result->itemsProcessed, pagesProcessed: $result->pagesProcessed);
    }
}
