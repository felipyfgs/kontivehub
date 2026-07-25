<?php

namespace App\Contracts;

use App\DTO\Mailbox\DteIndicatorResult;

/**
 * Indicador DTE — proveniência separada da Caixa Postal.
 */
interface DteIndicatorClient
{
    /**
     * @param  array<string, mixed>  $context  office_id, client_id, cnpj…
     */
    public function getIndicator(array $context = []): DteIndicatorResult;
}
