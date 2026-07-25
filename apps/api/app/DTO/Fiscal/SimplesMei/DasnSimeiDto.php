<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use InvalidArgumentException;

final readonly class DasnSimeiDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $year,
        public string $status,
        public ?string $receiptNumber,
        public FiscalSituation $situation,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body, string $fallbackYear = ''): self
    {
        $version = (string) ($body['dto_version'] ?? $body['version'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("DASN-SIMEI DTO versão não suportada: {$version}");
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $status = strtoupper((string) ($data['status'] ?? $data['situacao'] ?? 'UNKNOWN'));
        $year = (string) ($data['year'] ?? $data['ano'] ?? $fallbackYear);
        $receipt = isset($data['receipt_number'])
            ? (string) $data['receipt_number']
            : (isset($data['numero_recibo']) ? (string) $data['numero_recibo'] : null);

        $situation = match ($status) {
            'ENTREGUE', 'TRANSMITIDA', 'OK', 'DELIVERED' => $receipt !== null
                ? FiscalSituation::UpToDate
                : FiscalSituation::Unknown,
            'PENDENTE', 'OMISSA', 'PENDING' => FiscalSituation::Pending,
            'INCONCLUSIVO', 'UNKNOWN', '' => FiscalSituation::Unknown,
            'NAO_APLICAVEL' => FiscalSituation::NotApplicable,
            default => FiscalSituation::Unknown,
        };

        return new self(
            version: self::VERSION,
            year: $year,
            status: $status,
            receiptNumber: $receipt !== '' ? $receipt : null,
            situation: $situation,
            raw: $data,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        return [
            'dto' => 'dasn_simei',
            'dto_version' => $this->version,
            'year' => $this->year,
            'status' => $this->status,
            'receipt_number' => $this->receiptNumber,
            'situation' => $this->situation->value,
            'regime_family' => 'MEI',
        ];
    }
}
