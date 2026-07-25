<?php

namespace App\Contracts;

use App\Domain\Sefaz\ManifestationResultDto;
use App\Enums\NfeManifestationType;

/**
 * Registro de Manifestação do Destinatário via NFeRecepcaoEvento4 (AN).
 * mTLS com A1 do destinatário; sem PEM em disco.
 */
interface SefazNfeManifestationClient
{
    /**
     * @param  array{pfx: string, password: string}  $certificate
     * @param  string  $authorCnpj  CNPJ 14 do destinatário (autor do evento)
     * @param  string  $accessKey  chave NF-e 44
     * @param  string|null  $justification  xJust (obrigatório para 210240, 15–255)
     * @param  int  $sequence  nSeqEvento (1 default)
     */
    public function register(
        array $certificate,
        string $authorCnpj,
        string $accessKey,
        NfeManifestationType $type,
        ?string $justification = null,
        int $sequence = 1,
    ): ManifestationResultDto;
}
