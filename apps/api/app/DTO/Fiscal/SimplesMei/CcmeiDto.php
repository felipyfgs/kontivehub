<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use App\Services\Fiscal\SimplesMei\CcmeiDadosCodec;

final readonly class CcmeiDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $status,
        public FiscalSituation $situation,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body): self
    {
        $decoded = (new CcmeiDadosCodec)->decode($body);

        return new self(
            version: self::VERSION,
            status: $decoded['status'],
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
            'situation' => $this->situation->value,
            'regime_family' => 'MEI',
        ];
    }
}
