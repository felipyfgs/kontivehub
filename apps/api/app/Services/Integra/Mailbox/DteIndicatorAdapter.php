<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\DteIndicatorClient;
use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\MailboxDteStatus;
use App\Enums\MailboxMessagesConsultStatus;

/**
 * Adapter indicador DTE — proveniência própria; não consulta mensagens.
 */
final class DteIndicatorAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly DteIndicatorClient $client,
        private readonly MailboxStateService $states,
    ) {}

    public function systemCode(): string
    {
        return 'INTEGRA_CAIXAPOSTAL';
    }

    public function serviceCode(): string
    {
        return 'DTE';
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
        return FiscalCoverage::Full;
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
            'cnpj' => $request->client->root_cnpj,
            'correlation_id' => $request->run->correlation_id,
        ]);

        $state = $this->states->applyDte(
            $request->office,
            $request->client,
            $result,
            $request->run->id,
        );

        // Invariante: mensagens permanecem UNKNOWN se nunca consultadas
        $messagesStatus = $state->messages_status?->value
            ?? MailboxMessagesConsultStatus::Unknown->value;

        if (! $result->success) {
            $evidence = json_encode([
                'operation' => 'INDICADOR',
                'source' => 'DTE_INDICATOR',
                'dte_status' => MailboxDteStatus::Error->value,
                'messages_status' => $messagesStatus,
                'simulated' => $result->simulated,
                'error_code' => $result->errorCode,
            ], JSON_THROW_ON_ERROR);

            return new FiscalAdapterResult(
                result: FiscalRunResult::Failed,
                situation: FiscalSituation::Error,
                coverage: FiscalCoverage::Full,
                evidenceBytes: $evidence,
                errorCode: $result->errorCode ?? 'DTE_INDICATOR_FAILED',
                errorMessage: $result->errorMessage ?? 'Falha no indicador DTE.',
            );
        }

        $evidence = json_encode([
            'operation' => 'INDICADOR',
            'source' => 'DTE_INDICATOR',
            'dte_status' => $result->status->value,
            // Explicitamente separado — UI não deve fundir
            'messages_status' => $messagesStatus,
            'simulated' => $result->simulated,
        ], JSON_THROW_ON_ERROR);

        $situation = match ($result->status) {
            MailboxDteStatus::Active => FiscalSituation::UpToDate,
            MailboxDteStatus::Inactive => FiscalSituation::NotApplicable,
            MailboxDteStatus::Error => FiscalSituation::Error,
            MailboxDteStatus::Unknown => FiscalSituation::Unknown,
        };

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $situation,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $result->sourceVersion,
            normalized: [
                'source' => 'DTE_INDICATOR',
                'dte_status' => $result->status->value,
                'messages_status' => $messagesStatus,
            ],
            itemsProcessed: 1,
            pagesProcessed: 1,
        );
    }
}
