<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;

/** Codec fail-closed da DEFIS / CONSDECREC144. */
final class DefisSpecificDeclarationCodec
{
    public const MAX_PDF_BYTES = 5_242_880;

    /**
     * @return array{documents:list<array{kind:string,bytes:string}>}
     */
    public function decode(mixed $dados): array
    {
        $root = is_string($dados) ? json_decode($dados, true) : $dados;
        if (is_array($root) && array_is_list($root)) {
            $root = $root[0] ?? null;
        }
        if (! is_array($root)) {
            throw new InvalidArgumentException('Resposta DEFIS 144 inválida.');
        }

        return [
            'documents' => [
                ['kind' => 'RECIBO', 'bytes' => $this->decodePdf($root['recibo'] ?? null)],
                ['kind' => 'DECLARACAO', 'bytes' => $this->decodePdf($root['declaracao'] ?? null)],
            ],
        ];
    }

    private function decodePdf(mixed $value): string
    {
        if (! is_string($value)) {
            throw new InvalidArgumentException('Resposta DEFIS 144 inválida.');
        }
        $base64 = preg_replace('/\s+/', '', $value) ?? '';
        if ($base64 === '' || strlen($base64) % 4 !== 0 || preg_match('/^[A-Za-z0-9+\/]*={0,2}$/', $base64) !== 1) {
            throw new InvalidArgumentException('Resposta DEFIS 144 inválida.');
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || base64_encode($bytes) !== $base64 || strlen($bytes) > self::MAX_PDF_BYTES || ! str_starts_with($bytes, '%PDF-')) {
            throw new InvalidArgumentException('Resposta DEFIS 144 inválida.');
        }

        return $bytes;
    }
}
