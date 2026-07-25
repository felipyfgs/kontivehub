<?php

namespace App\Services\Fiscal\SimplesMei;

use InvalidArgumentException;
use RuntimeException;

/**
 * Codec estrito de REGIMEAPURACAO/CONSULTARRESOLUCAO104.
 * Payload: anoCalendario obrigatório. Resposta: dados.textoResolucao em Base64.
 */
final class RegimeResolutionCodec
{
    public const OPERATION_KEY = 'regimeapuracao.consultarresolucao';

    public const MAX_TEXT_BYTES = 512 * 1024;

    public const MIN_YEAR = 2000;

    public const MAX_YEAR = 2100;

    /**
     * @return array{anoCalendario:int}
     */
    public function buildPayload(int|string $anoCalendario): array
    {
        return ['anoCalendario' => $this->assertValidYear($anoCalendario)];
    }

    public function assertValidYear(int|string $year): int
    {
        $raw = trim((string) $year);
        if (preg_match('/^\d{4}$/', $raw) !== 1) {
            throw new InvalidArgumentException(
                'anoCalendario deve conter exatamente quatro dígitos.'
            );
        }

        $value = (int) $raw;
        if ($value < self::MIN_YEAR || $value > self::MAX_YEAR) {
            throw new InvalidArgumentException(
                'anoCalendario inválido para CONSULTARRESOLUCAO104 (2000–2100).'
            );
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $payload  body/dados já resolvido
     * @return array{
     *   calendar_year:?int,
     *   text_bytes:string,
     *   byte_size:int,
     *   content_sha256:string,
     *   content_type:string
     * }
     */
    public function decode(array $payload, ?int $expectedYear = null): array
    {
        $root = $this->coerceRoot($payload);
        $base64 = $this->extractTextoResolucao($root);
        $bytes = $this->decodeStrictBase64($base64);

        $year = null;
        if (isset($root['anoCalendario'])) {
            $year = $this->assertValidYear((string) $root['anoCalendario']);
        } elseif ($expectedYear !== null) {
            $year = $this->assertValidYear($expectedYear);
        }

        return [
            'calendar_year' => $year,
            'text_bytes' => $bytes,
            'byte_size' => strlen($bytes),
            'content_sha256' => hash('sha256', $bytes),
            'content_type' => 'text/plain; charset=UTF-8',
        ];
    }

    /**
     * Remove textoResolucao (Base64) do corpo de evidência pública.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function sanitizePublic(array $node, array $descriptor): array
    {
        foreach (['textoResolucao', 'texto_resolucao'] as $key) {
            if (array_key_exists($key, $node)) {
                $node[$key] = $descriptor;
            }
        }
        if (isset($node['dados']) && is_array($node['dados'])) {
            $node['dados'] = $this->sanitizePublic($node['dados'], $descriptor);
        }
        if (isset($node['data']) && is_array($node['data'])) {
            $node['data'] = $this->sanitizePublic($node['data'], $descriptor);
        }

        return $node;
    }

    /**
     * Metadados públicos do descritor (sem bytes, Base64, path de vault ou texto).
     *
     * @return array{sanitized:bool,available:bool,kind:string,byte_size:int}
     */
    public function publicDescriptorMeta(int $byteSize, ?string $contentSha256 = null): array
    {
        // Hash fica só em logs internos; não entra na projeção pública.
        unset($contentSha256);

        return [
            'sanitized' => true,
            'available' => true,
            'kind' => 'TEXT',
            'byte_size' => $byteSize,
        ];
    }

    public function decodeStrictBase64(string $base64): string
    {
        $clean = preg_replace('/\s+/', '', $base64) ?? '';
        $length = strlen($clean);
        $padding = str_ends_with($clean, '==') ? 2 : (str_ends_with($clean, '=') ? 1 : 0);
        $body = $padding > 0 ? substr($clean, 0, -$padding) : $clean;

        if ($clean === ''
            || $length % 4 !== 0
            || str_contains($body, '=')
            || strspn($body, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/') !== strlen($body)
        ) {
            throw new RuntimeException('Base64 inválido.');
        }

        $decoded = base64_decode($clean, true);
        if ($decoded === false || base64_encode($decoded) !== $clean) {
            throw new RuntimeException('Base64 não canônico.');
        }
        if ($decoded === '') {
            throw new RuntimeException('Texto da resolução vazio.');
        }
        if (strlen($decoded) > self::MAX_TEXT_BYTES) {
            throw new RuntimeException('Texto da resolução excede o limite permitido.');
        }
        if (! mb_check_encoding($decoded, 'UTF-8')) {
            throw new RuntimeException('Texto da resolução não é UTF-8 válido.');
        }

        return $decoded;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<string, mixed>
     */
    private function coerceRoot(array $payload): array
    {
        if (isset($payload['textoResolucao']) || isset($payload['texto_resolucao'])) {
            return $payload;
        }

        $dados = $payload['dados'] ?? $payload['data'] ?? null;
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            if (! is_array($decoded)) {
                throw new InvalidArgumentException(
                    'Resposta Regime 104 inválida: dados não é JSON de objeto.'
                );
            }

            return $decoded;
        }
        if (is_array($dados)) {
            return $dados;
        }

        throw new InvalidArgumentException(
            'Resposta Regime 104 inválida: textoResolucao ausente.'
        );
    }

    /**
     * @param  array<string, mixed>  $root
     */
    private function extractTextoResolucao(array $root): string
    {
        foreach (['textoResolucao', 'texto_resolucao'] as $key) {
            if (isset($root[$key]) && is_string($root[$key]) && trim($root[$key]) !== '') {
                return trim($root[$key]);
            }
        }

        throw new InvalidArgumentException(
            'Resposta Regime 104 inválida: campo textoResolucao ausente ou vazio.'
        );
    }
}
