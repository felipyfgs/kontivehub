<?php

namespace App\Contracts;

use App\Domain\Adn\DistributionPageDto;
use App\Domain\Adn\EventsPageDto;

interface AdnContributorClient
{
    /**
     * GET /DFe/{NSU}?cnpjConsulta=&lote=
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function distribution(
        array $certificate,
        string $cnpjConsulta,
        int $lastNsu,
        bool $lote = true,
    ): DistributionPageDto;

    /**
     * GET /NFSe/{chave}/Eventos
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function events(
        array $certificate,
        string $accessKey,
    ): EventsPageDto;
}
