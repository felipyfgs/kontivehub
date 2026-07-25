<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;

/**
 * Valida a resposta documental oficial de CCMEI / EMITIRCCMEI121.
 *
 * O CNPJ retornado é usado somente para conferir o titular no serviço de
 * domínio; nunca deve ser exposto pela projeção, API ou log.
 */
final class CcmeiCertificateIssuanceCodec
{
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    /**
     * @return array{contributor_cnpj:string,contents:string,mime_type:'application/pdf',sha256:string}
     */
    public function decode(mixed $dados): array
    {
        $payload = $this->decodeDados($dados);
        $contributor = $this->cnpj($payload['cnpj'] ?? null);
        $contents = $this->pdf($payload['pdf'] ?? null);

        return [
            'contributor_cnpj' => $contributor,
            'contents' => $contents,
            'mime_type' => 'application/pdf',
            'sha256' => hash('sha256', $contents),
        ];
    }

    /** @return array<string, mixed> */
    private function decodeDados(mixed $dados): array
    {
        if (is_string($dados)) {
            try {
                $dados = json_decode($dados, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
            }
        }

        if (! is_array($dados) || array_is_list($dados)) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }

        return $dados;
    }

    private function cnpj(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }
        $value = strtoupper(trim($value));

        if (preg_match('/^[A-Z0-9]{14}$/', $value) !== 1) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }

        return $value;
    }

    private function pdf(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }
        $base64 = preg_replace('/\s+/', '', $value) ?? '';
        $padding = str_ends_with($base64, '==') ? 2 : (str_ends_with($base64, '=') ? 1 : 0);
        $body = $padding > 0 ? substr($base64, 0, -$padding) : $base64;

        if ($base64 === ''
            || strlen($base64) % 4 !== 0
            || str_contains($body, '=')
            || strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') !== strlen($body)
        ) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }

        $contents = base64_decode($base64, true);
        if ($contents === false
            || base64_encode($contents) !== $base64
            || strlen($contents) > self::MAX_PDF_BYTES
            || ! str_starts_with($contents, '%PDF-')
        ) {
            throw new InvalidArgumentException('Resposta CCMEI 121 inválida.');
        }

        return $contents;
    }
}
