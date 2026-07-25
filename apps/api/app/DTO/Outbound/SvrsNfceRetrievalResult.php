<?php

namespace App\DTO\Outbound;

use App\Enums\SvrsNfceTransportOutcome;

/**
 * Resultado tipado do cliente SVRS — nunca carrega HTML bruto para camadas superiores
 * exceto em caminho interno do parser (body só entre transporte→parser).
 */
final readonly class SvrsNfceRetrievalResult
{
    /**
     * @param  array<string, string>  $responseHeaders
     */
    public function __construct(
        public SvrsNfceTransportOutcome $outcome,
        public ?string $xmlBytes = null,
        public ?string $sha256 = null,
        public ?int $httpStatus = null,
        public ?int $retryAfterSeconds = null,
        public ?string $parserVersion = null,
        public ?int $getLatencyMs = null,
        public ?int $postLatencyMs = null,
        public ?int $totalLatencyMs = null,
        public ?string $sanitizedDetail = null,
        public array $responseHeaders = [],
    ) {}

    public function isSuccess(): bool
    {
        return $this->outcome === SvrsNfceTransportOutcome::Captured
            && is_string($this->xmlBytes)
            && $this->xmlBytes !== '';
    }
}
