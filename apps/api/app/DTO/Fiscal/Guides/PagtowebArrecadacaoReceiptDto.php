<?php

namespace App\DTO\Fiscal\Guides;

use App\Services\Fiscal\Guides\PagtowebArrecadacaoReceiptCodec;

/** DTO interno de bytes documentais; nunca retornar diretamente pela API. */
final readonly class PagtowebArrecadacaoReceiptDto
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $sha256,
    ) {}

    public static function fromDados(mixed $dados): self
    {
        $decoded = (new PagtowebArrecadacaoReceiptCodec)->decodePdf($dados);

        return new self(
            contents: $decoded['contents'],
            mimeType: $decoded['mime_type'],
            sha256: $decoded['sha256'],
        );
    }
}
