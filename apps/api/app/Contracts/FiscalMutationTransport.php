<?php

namespace App\Contracts;

use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;

/**
 * Transporte de operação mutante (emissão/transmissão).
 * Implementações reais usam Integra Contador; fakes controlam timeout/resultado em teste.
 */
interface FiscalMutationTransport
{
    public function execute(IntegraRequest $request): IntegraResponse;

    /**
     * Consulta de reconciliação específica por serviço (sem reenviar mutação).
     */
    public function reconcile(IntegraRequest $request): IntegraResponse;
}
