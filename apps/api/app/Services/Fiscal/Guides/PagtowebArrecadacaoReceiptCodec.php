<?php

namespace App\Services\Fiscal\Guides;

use InvalidArgumentException;

/** Contrato estrito do PAGTOWEB/COMPARRECADACAO72; o documento nunca é serializado à API local. */
final class PagtowebArrecadacaoReceiptCodec
{
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    /** @return array{numeroDocumento:string} */
    public function normalizeRequest(mixed $numeroDocumento): array
    {
        if (! is_string($numeroDocumento) || trim($numeroDocumento) === '' || strlen($numeroDocumento) > 17 || preg_match('/[\x00-\x1F\x7F]/', $numeroDocumento) === 1) {
            throw new InvalidArgumentException('Número do documento de arrecadação inválido.');
        }

        return ['numeroDocumento' => trim($numeroDocumento)];
    }

    /** @return array{contents:string,mime_type:'application/pdf',sha256:string} */
    public function decodePdf(mixed $dados): array
    {
        if (is_array($dados) && array_key_exists('dados', $dados)) {
            $dados = $dados['dados'];
        }
        if (! is_string($dados) || $dados === '') {
            throw new InvalidArgumentException('Comprovante de arrecadação ausente.');
        }

        $contents = base64_decode($dados, true);
        $canonical = $contents !== false && base64_encode($contents) === $dados;
        if (! $canonical || $contents === '' || strlen($contents) > self::MAX_PDF_BYTES || ! str_starts_with($contents, '%PDF-')) {
            throw new InvalidArgumentException('Comprovante de arrecadação inválido.');
        }

        return [
            'contents' => $contents,
            'mime_type' => 'application/pdf',
            'sha256' => hash('sha256', $contents),
        ];
    }
}
