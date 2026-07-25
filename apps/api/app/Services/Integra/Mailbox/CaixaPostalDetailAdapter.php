<?php

namespace App\Services\Integra\Mailbox;

use App\Contracts\CaixaPostalClient;
use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Models\MailboxMessage;

/**
 * Adapter detalhe de mensagem — operação DETALHE, separada de LISTAR e DTE.
 */
final class CaixaPostalDetailAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly CaixaPostalClient $client,
        private readonly MailboxMessageStore $store,
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
        return 'DETALHE';
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
        $externalId = (string) ($request->context['external_message_id']
            ?? $request->progress['external_message_id']
            ?? '');

        if ($externalId === '') {
            $messageId = (int) ($request->context['message_id']
                ?? $request->progress['message_id']
                ?? 0);
            if ($messageId > 0) {
                $externalId = (string) (MailboxMessage::query()
                    ->withoutGlobalScopes()
                    ->where('office_id', $request->office->id)
                    ->where('client_id', $request->client->id)
                    ->whereKey($messageId)
                    ->value('external_id') ?? '');
            }
        }

        if ($externalId === '') {
            return FiscalAdapterResult::skipped(
                'Identificador da mensagem ausente ou fora do tenant da run.',
                'MAILBOX_DETAIL_ID_MISSING',
            );
        }

        $detail = $this->client->getMessageDetail($externalId, [
            'office_id' => $request->office->id,
            'client_id' => $request->client->id,
            'correlation_id' => $request->run->correlation_id,
        ]);

        if (! $detail->success) {
            return FiscalAdapterResult::failed(
                $detail->errorMessage ?? 'Falha ao obter detalhe da mensagem.',
                $detail->errorCode ?? 'MAILBOX_DETAIL_FAILED',
            );
        }

        $msg = $this->store->applyDetail(
            $request->office,
            $request->client,
            $detail,
            $request->run->id,
        );

        // Evidência: metadados + hashes — sem corpo em claro no JSON de evidência
        // (bytes já no cofre de mensagem; evidência da run guarda resumo)
        $evidence = json_encode([
            'operation' => 'DETALHE',
            'source' => 'CAIXA_POSTAL',
            'simulated' => $detail->simulated,
            'external_id' => $detail->externalId,
            'message_id' => $msg->id,
            'body_sha256' => $msg->body_sha256,
            'body_byte_size' => $msg->body_byte_size,
            'attachment_count' => $msg->attachment_count,
            'has_body' => $msg->has_body,
            'official_read_indicator' => $msg->official_read_indicator,
            // triagem interna não é estado oficial
            'triage_status' => $msg->triage_status?->value,
        ], JSON_THROW_ON_ERROR);

        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: FiscalSituation::UpToDate,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidence,
            evidenceContentType: 'application/json',
            sourceVersion: $detail->sourceVersion,
            normalized: [
                'source' => 'CAIXA_POSTAL',
                'message_id' => $msg->id,
                'external_id' => $detail->externalId,
                'has_body' => $msg->has_body,
            ],
            itemsProcessed: 1,
            pagesProcessed: 1,
        );
    }
}
