<?php

namespace App\Services\Integra\Registrations;

use RuntimeException;

/** Valida o comprovante PDF Base64 retornado pelo PNR Contador. */
final class PnrRenunciationReceiptCodec
{
    /** @return array{contents: string, mime_type: 'application/pdf', sha256: string} */
    public function decode(mixed $dados): array
    {
        if (! is_string($dados) || trim($dados) === '') {
            throw new RuntimeException('Resposta PNR Contador inválida: comprovante Base64 ausente.');
        }

        $contents = base64_decode($dados, true);
        if (! is_string($contents) || ! str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('Resposta PNR Contador inválida: comprovante não é PDF Base64 válido.');
        }

        return [
            'contents' => $contents,
            'mime_type' => 'application/pdf',
            'sha256' => hash('sha256', $contents),
        ];
    }
}
