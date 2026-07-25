<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\DctfwebCategory;
use App\Services\Fiscal\Dctfweb\DctfwebPeriod;
use RuntimeException;

/** Codec comum dos documentos DCTFWeb 31/32/33/38/313. */
final class DctfwebOfficialCodec
{
    public const MAX_DOCUMENT_BYTES = 10 * 1024 * 1024;

    /** @return array{categoria:string,anoPA:string,mesPA:string} */
    public function periodPayload(string $periodKey): array
    {
        $period = DctfwebPeriod::parse($periodKey);

        return [
            'categoria' => DctfwebCategory::default()->officialCode(),
            'anoPA' => DctfwebPeriod::toAnoPa($period),
            'mesPA' => DctfwebPeriod::toMesPa($period),
        ];
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public function decodePdf(array $dados): string
    {
        $bytes = $this->decodeField($dados, [
            'PDFByteArrayBase64',
            'pdfByteArrayBase64',
            'pdf_byte_array_base64',
        ]);
        if (! str_starts_with($bytes, '%PDF-')) {
            throw new RuntimeException('Documento DCTFWeb não possui assinatura PDF válida.');
        }

        return $bytes;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    public function decodeXml(array $dados): string
    {
        $bytes = $this->decodeField($dados, [
            'XMLStringBase64',
            'xmlStringBase64',
            'xml_string_base64',
        ]);
        $prefix = ltrim(substr($bytes, 0, 128));
        if (! str_starts_with($prefix, '<?xml') && ! str_starts_with($prefix, '<')) {
            throw new RuntimeException('Documento DCTFWeb não possui conteúdo XML válido.');
        }

        return $bytes;
    }

    /**
     * Remove o documento Base64 antes de repassar metadados a projeções.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    public function sanitize(array $dados): array
    {
        foreach ($dados as $key => $value) {
            if (is_string($key) && str_ends_with(strtolower($key), 'base64')) {
                $dados[$key] = [
                    'redacted' => true,
                    'byte_size' => is_string($value) ? strlen($value) : null,
                ];
            }
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @param  list<string>  $candidates
     */
    private function decodeField(array $dados, array $candidates): string
    {
        $encoded = null;
        foreach ($candidates as $field) {
            if (isset($dados[$field]) && is_string($dados[$field]) && trim($dados[$field]) !== '') {
                $encoded = preg_replace('/\s+/', '', $dados[$field]) ?? '';
                break;
            }
        }

        if (! is_string($encoded) || $encoded === '') {
            throw new RuntimeException('Campo documental Base64 ausente na resposta DCTFWeb.');
        }

        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) > self::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('Documento Base64 DCTFWeb inválido ou acima de 10 MiB.');
        }

        return $bytes;
    }
}
