<?php

namespace App\Contracts;

use App\Domain\Sefaz\DistDfePageDto;

/**
 * Distribuição NF-e DistDFe (Ambiente Nacional) — transporte próprio, sem sped-nfe runtime.
 */
interface SefazDistDfeClient
{
    /**
     * Consulta sequencial por NSU (distNSU / ultNSU).
     *
     * @param  array{pfx: string, password: string}  $certificate
     * @param  string  $cnpjConsulta  14 chars (base do cert deve coincidir)
     * @param  string  $cUfAutor  UF IBGE 2 dígitos do autor
     */
    public function distByNsu(
        array $certificate,
        string $cnpjConsulta,
        int $ultNsu,
        string $cUfAutor,
    ): DistDfePageDto;

    /**
     * Consulta pontual por chave (consChNFe) — uso restrito (pós-manifestação / suporte).
     *
     * @param  array{pfx: string, password: string}  $certificate
     */
    public function distByAccessKey(
        array $certificate,
        string $cnpjConsulta,
        string $accessKey,
        string $cUfAutor,
    ): DistDfePageDto;
}
