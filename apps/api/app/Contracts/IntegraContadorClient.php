<?php

namespace App\Contracts;

use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;

/**
 * Fachada de domínio para chamadas Integra Contador.
 * Recebe/retorna DTOs — nunca JSON bruto no domínio de jobs.
 */
interface IntegraContadorClient
{
    public function execute(IntegraRequest $request): IntegraResponse;
}
