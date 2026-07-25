<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Services\Fiscal\SimplesMei\CcmeiCertificateIssuanceCodec;

/** DTO interno; não serializa titular ou bytes documentais para a API. */
final readonly class CcmeiCertificateIssuanceDto
{
    public function __construct(
        public string $contributorCnpj,
        public string $contents,
        public string $mimeType,
        public string $sha256,
    ) {}

    public static function fromDados(mixed $dados): self
    {
        $decoded = (new CcmeiCertificateIssuanceCodec)->decode($dados);

        return new self(
            contributorCnpj: $decoded['contributor_cnpj'],
            contents: $decoded['contents'],
            mimeType: $decoded['mime_type'],
            sha256: $decoded['sha256'],
        );
    }
}
