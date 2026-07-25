<?php

namespace App\DTO\FgtsDigital;

use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;

final readonly class FgtsDigitalPortalResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{name:string,content_type:string,bytes:string,sha256:string}>  $artifacts
     * @param  array<string, mixed>|null  $storageState
     */
    public function __construct(
        public string $status,
        public string $code,
        public string $message,
        public array $data,
        public array $artifacts = [],
        public ?array $storageState = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromTransportArray(array $payload): self
    {
        if ((int) ($payload['contract_version'] ?? 0) !== (int) config('fgts_digital.contract_version', 1)) {
            throw new FgtsDigitalException('Versão do contrato RPA incompatível.', 'RPA_CONTRACT_VERSION_MISMATCH', 502);
        }

        $artifacts = [];
        foreach (($payload['artifacts'] ?? []) as $artifact) {
            if (! is_array($artifact)) {
                throw new FgtsDigitalException('Artefato RPA inválido.', 'RPA_INVALID_RESPONSE', 502);
            }
            $bytes = base64_decode((string) ($artifact['content_base64'] ?? ''), true);
            $contentType = (string) ($artifact['content_type'] ?? '');
            if ($bytes === false || strlen($bytes) < 8
                || strlen($bytes) > (int) config('fgts_digital.runtime.max_output_bytes', 8_388_608)
                || $contentType !== 'application/pdf'
                || ! str_starts_with($bytes, '%PDF-')) {
                throw new FgtsDigitalException('Artefato RPA vazio ou inválido.', 'RPA_INVALID_ARTIFACT', 502);
            }
            $sha = hash('sha256', $bytes);
            if (isset($artifact['sha256']) && ! hash_equals((string) $artifact['sha256'], $sha)) {
                throw new FgtsDigitalException('Hash do artefato RPA diverge.', 'RPA_ARTIFACT_DIGEST_MISMATCH', 502);
            }
            $artifacts[] = [
                'name' => basename((string) ($artifact['name'] ?? 'guia.pdf')),
                'content_type' => $contentType,
                'bytes' => $bytes,
                'sha256' => $sha,
            ];
        }

        return new self(
            status: (string) ($payload['status'] ?? 'FAILED'),
            code: (string) ($payload['code'] ?? 'RPA_INVALID_RESPONSE'),
            message: (string) ($payload['message'] ?? 'Resposta inválida do runtime RPA.'),
            data: is_array($payload['data'] ?? null) ? $payload['data'] : [],
            artifacts: $artifacts,
            storageState: is_array($payload['session'] ?? null) ? $payload['session'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'status' => $this->status,
            'code' => $this->code,
            'message' => $this->message,
            'data' => self::sanitizePublicValue($this->data),
            'artifacts' => array_map(static fn (array $artifact): array => [
                'name' => $artifact['name'],
                'content_type' => $artifact['content_type'],
                'byte_size' => strlen($artifact['bytes']),
                'sha256' => $artifact['sha256'],
            ], $this->artifacts),
            'session_refreshed' => $this->storageState !== null,
        ];
    }

    private static function sanitizePublicValue(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:pfx|passphrase|password|cookie|captcha|api.?key|proxy|token|html|storage.?state)/i', $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = self::sanitizePublicValue($childValue, (string) $childKey);
            }

            return $sanitized;
        }
        if (is_string($value)) {
            return preg_replace('/(?<!\d)(?:\d[.\/-]?){11,14}(?!\d)/', '[REDACTED]', $value) ?? '[REDACTED]';
        }

        return $value;
    }
}
