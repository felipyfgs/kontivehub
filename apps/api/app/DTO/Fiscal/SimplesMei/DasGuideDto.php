<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalGuideEmissionStatus;
use App\Enums\FiscalGuidePaymentStatus;

/**
 * DAS gerado de forma assistida — emissão ≠ pagamento.
 */
final readonly class DasGuideDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $competence,
        public string $regimeFamily,
        public ?string $documentNumber,
        public ?string $dueDate,
        public ?float $amount,
        public FiscalGuideEmissionStatus $emissionStatus,
        public FiscalGuidePaymentStatus $paymentStatus,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body, string $fallbackCompetence = '', string $regimeFamily = 'SIMPLES_NACIONAL'): self
    {
        $data = self::unwrapPayload($body);
        $competence = (string) ($data['periodoApuracao'] ?? $fallbackCompetence);
        $doc = self::firstString($data, ['numeroDocumento']);
        $due = self::firstString($data, ['dataVencimento']);
        $amount = self::firstFloat($data, [
            'total',
            'principal',
        ]);

        $emissionRaw = strtoupper((string) ($data['emission_status'] ?? 'ISSUED'));
        $emission = FiscalGuideEmissionStatus::tryFrom($emissionRaw) ?? FiscalGuideEmissionStatus::Issued;

        // Nunca inferir pagamento a partir da emissão
        $payment = FiscalGuidePaymentStatus::Unknown;

        return new self(
            version: self::VERSION,
            competence: $competence,
            regimeFamily: $regimeFamily,
            documentNumber: $doc,
            dueDate: $due,
            amount: $amount,
            emissionStatus: $emission,
            paymentStatus: $payment,
            raw: $data,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private static function unwrapPayload(array $body): array
    {
        if (isset($body['dados']) && is_array($body['dados'])) {
            return $body['dados'];
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function firstString(array $data, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null) {
                continue;
            }
            $value = trim((string) $data[$key]);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private static function firstFloat(array $data, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data) || $data[$key] === null || $data[$key] === '') {
                continue;
            }
            if (! is_numeric($data[$key])) {
                continue;
            }

            return (float) $data[$key];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalized(): array
    {
        return [
            'dto' => 'das_guide',
            'dto_version' => $this->version,
            'competence' => $this->competence,
            'regime_family' => $this->regimeFamily,
            'document_number' => $this->documentNumber,
            'due_date' => $this->dueDate,
            'amount' => $this->amount,
            'emission_status' => $this->emissionStatus->value,
            'payment_status' => $this->paymentStatus->value,
            'payment_inferred' => false,
        ];
    }
}
