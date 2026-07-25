<?php

namespace App\Contracts;

use App\Domain\Sefaz\DistDfePageDto;

/**
 * Distribuição CT-e (CTeDistribuicaoDFe) — cursor NSU independente de NF-e.
 *
 * distByLastNsu / distByNsu: fluxo sequencial (Scheduler).
 * findByNsu: reparo pontual de NSU conhecido — nunca varredura/descoberta/chave.
 */
interface SefazCteDistDfeClient
{
    /**
     * Consulta sequencial por último NSU (distNSU / ultNSU).
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function distByLastNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto;

    /**
     * Alias de compatibilidade — mesmo contrato de distByLastNsu.
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function distByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto;

    /**
     * Consulta pontual por NSU conhecido (consNSU).
     * MUST NOT ser usado como varredura, descoberta ou consulta por chave.
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function findByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $nsu,
        string $cUfAutor,
    ): DistDfePageDto;
}
