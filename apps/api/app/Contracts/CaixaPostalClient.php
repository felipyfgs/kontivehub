<?php

namespace App\Contracts;

use App\DTO\Mailbox\CaixaPostalDetailResult;
use App\DTO\Mailbox\CaixaPostalListResult;

/**
 * Adapter de fonte Caixa Postal (lista/detalhe).
 * Implementações: Fake (CI) e HTTP futuro — domínio nunca chama SERPRO direto.
 */
interface CaixaPostalClient
{
    /**
     * @param  array<string, mixed>  $context  office_id, client_id, cnpj, correlation_id…
     */
    public function listMessages(array $context = []): CaixaPostalListResult;

    /**
     * @param  array<string, mixed>  $context
     */
    public function getMessageDetail(string $externalMessageId, array $context = []): CaixaPostalDetailResult;
}
