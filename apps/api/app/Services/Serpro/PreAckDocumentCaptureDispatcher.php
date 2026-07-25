<?php

namespace App\Services\Serpro;

use App\DTO\Serpro\IntegraResponse;
use App\Services\Fiscal\Guides\PagtowebArrecadacaoReceiptPreAckStore;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPreAckDocumentStore;

/** Despacha respostas documentais ao cofre antes de o attempt receber ACK terminal. */
final class PreAckDocumentCaptureDispatcher
{
    public function __construct(
        private readonly PgdasdPreAckDocumentStore $pgdasd,
        private readonly PagtowebArrecadacaoReceiptPreAckStore $pagtoweb,
    ) {}

    public function handles(string $operationKey): bool
    {
        return in_array($operationKey, [
            'pgdasd.consultimadecrec',
            'pgdasd.consdecrec',
            'pgdasd.consextrato',
            'pagtoweb.comparrecadacao',
        ], true);
    }

    public function capture(string $operationKey, string $entityKey, IntegraResponse $response, int $officeId, int $clientId): IntegraResponse
    {
        return $operationKey === 'pagtoweb.comparrecadacao'
            ? $this->pagtoweb->capture($operationKey, $entityKey, $response, $officeId, $clientId)
            : $this->pgdasd->capture($operationKey, $entityKey, $response, $officeId, $clientId);
    }
}
