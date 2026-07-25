<?php

namespace App\Services\Fiscal\Dctfweb;

use App\Enums\DctfwebCategory;
use InvalidArgumentException;
use RuntimeException;

/**
 * Codec estrito de CONSRECIBO32: payload oficial e PDFByteArrayBase64.
 */
final class DctfwebConsReciboCodec
{
    public const MAX_PDF_BYTES = 10 * 1024 * 1024;

    public const OPERATION_KEY = 'dctfweb.consrecibo';

    /**
     * @return array{categoria:string,anoPA:string,mesPA:string}
     */
    public function buildPayload(
        string $anoPa,
        string $mesPa,
        DctfwebCategory|string $category = DctfwebCategory::GeralMensal,
    ): array {
        $ano = trim($anoPa);
        $mes = str_pad(trim($mesPa), 2, '0', STR_PAD_LEFT);
        if (preg_match('/^\d{4}$/', $ano) !== 1 || (int) $ano < 2000 || (int) $ano > 2100) {
            throw new InvalidArgumentException('anoPA inválido para CONSRECIBO32.');
        }
        if (preg_match('/^(0[1-9]|1[0-2])$/', $mes) !== 1) {
            throw new InvalidArgumentException('mesPA inválido para CONSRECIBO32.');
        }

        $cat = $category instanceof DctfwebCategory
            ? $category
            : (DctfwebCategory::fromOfficialCode($category) ?? DctfwebCategory::default());

        return [
            'categoria' => $cat->officialCode(),
            'anoPA' => $ano,
            'mesPA' => $mes,
        ];
    }

    /**
     * Localiza PDFByteArrayBase64 no `dados` normalizado (string JSON ou array).
     *
     * @return array{base64:string,path:string,root:array<string,mixed>}
     */
    public function extractPdfField(mixed $dados): array
    {
        $root = $this->coerceArray($dados);
        $candidates = [
            'PDFByteArrayBase64',
            'pdfByteArrayBase64',
            'pdf_byte_array_base64',
        ];

        foreach ($candidates as $key) {
            if (isset($root[$key]) && is_string($root[$key]) && trim($root[$key]) !== '') {
                return [
                    'base64' => trim($root[$key]),
                    'path' => $key,
                    'root' => $root,
                ];
            }
        }

        // Alguns envelopes aninham sob "dados" ou "recibo".
        foreach (['dados', 'recibo', 'data'] as $nestedKey) {
            if (! isset($root[$nestedKey])) {
                continue;
            }
            $nested = $this->coerceArray($root[$nestedKey]);
            foreach ($candidates as $key) {
                if (isset($nested[$key]) && is_string($nested[$key]) && trim($nested[$key]) !== '') {
                    return [
                        'base64' => trim($nested[$key]),
                        'path' => $nestedKey.'.'.$key,
                        'root' => $root,
                    ];
                }
            }
        }

        throw new RuntimeException('PDFByteArrayBase64 ausente na resposta CONSRECIBO32.');
    }

    /**
     * Decodifica Base64 estrito, limita 10 MiB e exige assinatura %PDF-.
     */
    public function decodePdf(string $base64): string
    {
        $clean = preg_replace('/\s+/', '', $base64) ?? '';
        if ($clean === ''
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{4}|[A-Za-z0-9+\/]{3}=|[A-Za-z0-9+\/]{2}==)$/', $clean) !== 1
        ) {
            throw new RuntimeException('Base64 inválido.');
        }

        $decoded = base64_decode($clean, true);
        if ($decoded === false || base64_encode($decoded) !== $clean) {
            throw new RuntimeException('Base64 não canônico.');
        }

        if (strlen($decoded) > self::MAX_PDF_BYTES) {
            throw new RuntimeException('PDF excede o limite de 10 MiB.');
        }

        if (strlen($decoded) < 5 || ! str_starts_with($decoded, '%PDF-')) {
            throw new RuntimeException('Assinatura PDF inválida.');
        }

        return $decoded;
    }

    /**
     * Parser opcional de texto do PDF (sem movimento / recibo). Fail-soft.
     *
     * @return array{
     *   no_movement: bool|null,
     *   receipt_number: ?string,
     *   declaration_type: ?string,
     *   parser_version: string
     * }
     */
    public function parsePdfHints(string $pdfBytes): array
    {
        $text = $this->extractRoughText($pdfBytes);
        $upper = mb_strtoupper($text);

        $noMovement = null;
        if ($upper !== '') {
            if (str_contains($upper, 'SEM MOVIMENTO')
                || str_contains($upper, 'SEM MOVIMENTAÇÃO')
                || str_contains($upper, 'SEM MOVIMENTACAO')
            ) {
                $noMovement = true;
            } elseif (str_contains($upper, 'COM MOVIMENTO') || str_contains($upper, 'COM MOVIMENTAÇÃO')) {
                $noMovement = false;
            }
        }

        $receipt = null;
        if (preg_match('/RECIBO[:\s#]*([0-9.\-\/]{6,40})/iu', $text, $m) === 1) {
            $receipt = preg_replace('/\s+/', '', $m[1]) ?: null;
        }

        $type = null;
        if (str_contains($upper, 'RETIFICADORA') || str_contains($upper, 'RETIFICAÇÃO') || str_contains($upper, 'RETIFICACAO')) {
            $type = 'RECTIFICADORA';
        } elseif (str_contains($upper, 'ORIGINAL')) {
            $type = 'ORIGINAL';
        }

        return [
            'no_movement' => $noMovement,
            'receipt_number' => $receipt,
            'declaration_type' => $type,
            'parser_version' => 'dctfweb-pdf-hints-1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizeDados(mixed $dados, array $descriptor): array
    {
        $root = $this->coerceArray($dados);
        foreach (['PDFByteArrayBase64', 'pdfByteArrayBase64', 'pdf_byte_array_base64'] as $key) {
            if (array_key_exists($key, $root)) {
                $root[$key] = $descriptor;
            }
        }
        foreach (['dados', 'recibo', 'data'] as $nestedKey) {
            if (! isset($root[$nestedKey]) || ! is_array($root[$nestedKey])) {
                continue;
            }
            foreach (['PDFByteArrayBase64', 'pdfByteArrayBase64', 'pdf_byte_array_base64'] as $key) {
                if (array_key_exists($key, $root[$nestedKey])) {
                    $root[$nestedKey][$key] = $descriptor;
                }
            }
        }

        return $root;
    }

    /** @return array<string, mixed> */
    private function coerceArray(mixed $dados): array
    {
        if ($dados === null || $dados === '') {
            throw new RuntimeException('Resposta CONSRECIBO32 sem dados.');
        }
        if (is_string($dados)) {
            $trimmed = trim($dados);
            // Às vezes `dados` é o próprio Base64 do PDF (sem envelope).
            if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Resposta CONSRECIBO32: dados não é JSON de objeto.');
                }

                return $decoded;
            }

            return ['PDFByteArrayBase64' => $trimmed];
        }
        if (! is_array($dados)) {
            throw new RuntimeException('Resposta CONSRECIBO32: dados em formato inválido.');
        }

        return $dados;
    }

    private function extractRoughText(string $pdfBytes): string
    {
        // Extração best-effort de literais entre parênteses (sem dependência externa).
        if (preg_match_all('/\((?:\\\\.|[^\\\\)]){2,200}\)/s', $pdfBytes, $matches) < 1) {
            return '';
        }

        $parts = [];
        foreach ($matches[0] as $raw) {
            $inner = substr($raw, 1, -1);
            $inner = str_replace(['\\(', '\\)', '\\\\', '\\n', '\\r'], ['(', ')', '\\', "\n", ''], $inner);
            $inner = preg_replace('/[^\P{C}\n]+/u', '', $inner) ?? '';
            if (trim($inner) !== '') {
                $parts[] = $inner;
            }
        }

        return implode(' ', array_slice($parts, 0, 400));
    }
}
