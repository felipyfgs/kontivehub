<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use App\Services\Fiscal\SimplesMei\CcmeiDadosCodec;
use InvalidArgumentException;

final readonly class CcmeiDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $status,
        public ?string $certificateNumber,
        public ?string $issuedAt,
        public FiscalSituation $situation,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body): self
    {
        $version = (string) ($body['dto_version'] ?? $body['version'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("CCMEI DTO versão não suportada: {$version}");
        }

        $decoded = (new CcmeiDadosCodec)->decode($body);

        return new self(
            version: self::VERSION,
            status: $decoded['status'],
            certificateNumber: $decoded['certificate_number'],
            issuedAt: $decoded['issued_at'],
            situation: $decoded['situation'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        return [
            'dto' => 'ccmei',
            'dto_version' => $this->version,
            'status' => $this->status,
            'certificate_number' => $this->certificateNumber,
            'issued_at' => $this->issuedAt,
            'situation' => $this->situation->value,
            'regime_family' => 'MEI',
        ];
    }
}
