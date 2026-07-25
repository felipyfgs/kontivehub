<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\CaixaPostalIndicatorClient;
use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;

final class CaixaPostalIndicatorAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly CaixaPostalIndicatorClient $client,
        private readonly MailboxStateService $states,
    ) {}

    public function systemCode(): string
    {
        return 'INTEGRA_CAIXAPOSTAL';
    }

    public function serviceCode(): string
    {
        return 'CAIXA_POSTAL';
    }

    public function operationCode(): string
    {
        return 'INDICADOR';
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function moduleKey(): ?string
    {
        return 'mailbox';
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->systemCode()) === 0
            && strcasecmp($request->serviceCode, $this->serviceCode()) === 0
            && strcasecmp($request->operationCode, $this->operationCode()) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $result = $this->client->getIndicator([
            'office_id' => $request->office->id,
            'client_id' => $request->client->id,
            'correlation_id' => $request->run->correlation_id,
        ]);

        if (! $result->success || $result->indicator === null) {
            return new FiscalAdapterResult(
                result: FiscalRunResult::Failed,
                situation: FiscalSituation::Error,
                coverage: FiscalCoverage::Partial,
                evidenceBytes: json_encode([
                    'operation' => 'INDICADOR',
                    'source' => 'CAIXA_POSTAL_NEW_MESSAGES_INDICATOR',
                    'semantic' => 'UNOPENED_ONLY',
                    'error_code' => $result->errorCode,
                ], JSON_THROW_ON_ERROR),
                errorCode: $result->errorCode ?? 'MAILBOX_INDICATOR_FAILED',
                errorMessage: $result->errorMessage ?? 'Falha no indicador de mensagens novas.',
            );
        }

        $this->states->applyNewMessagesIndicator(
            $request->office,
            $request->client,
            $result->indicator,
            $request->run->id,
        );

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $result->indicator > 0 ? FiscalSituation::Attention : FiscalSituation::Unknown,
            coverage: FiscalCoverage::Partial,
            evidenceBytes: json_encode([
                'operation' => 'INDICADOR',
                'source' => 'CAIXA_POSTAL_NEW_MESSAGES_INDICATOR',
                'semantic' => 'UNOPENED_ONLY',
                'indicator' => $result->indicator,
            ], JSON_THROW_ON_ERROR),
            sourceVersion: $result->sourceVersion,
            normalized: [
                'indicator' => $result->indicator,
                'semantic' => 'UNOPENED_ONLY',
                'reconciles_mailbox' => false,
            ],
            itemsProcessed: 1,
            pagesProcessed: 1,
        );
    }
}
