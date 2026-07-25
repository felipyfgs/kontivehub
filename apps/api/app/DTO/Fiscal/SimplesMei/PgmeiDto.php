<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalSituation;
use InvalidArgumentException;

final readonly class PgmeiDto
{
    public const VERSION = '1';

    public function __construct(
        public string $version,
        public string $competence,
        public string $status,
        public ?string $dasNumber,
        public ?string $dueDate,
        public ?float $amount,
        public FiscalSituation $situation,
        public array $raw,
    ) {}

    /**
     * @param  array<string, mixed>  $body
     */
    public static function fromIntegraBody(array $body, string $fallbackCompetence = ''): self
    {
        $version = (string) ($body['dto_version'] ?? $body['version'] ?? self::VERSION);
        if ($version !== self::VERSION) {
            throw new InvalidArgumentException("PGMEI DTO versão não suportada: {$version}");
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $status = strtoupper((string) ($data['status'] ?? $data['situacao'] ?? 'UNKNOWN'));
        $competence = (string) ($data['competence'] ?? $data['competencia'] ?? $fallbackCompetence);
        $das = isset($data['das_number'])
            ? (string) $data['das_number']
            : (isset($data['numero_das']) ? (string) $data['numero_das'] : null);
        $due = isset($data['due_date'])
            ? (string) $data['due_date']
            : (isset($data['vencimento']) ? (string) $data['vencimento'] : null);
        $amount = isset($data['amount'])
            ? (float) $data['amount']
            : (isset($data['valor']) ? (float) $data['valor'] : null);

        $situation = match ($status) {
            'EMITIDO', 'EMITIDA', 'OK', 'REGULAR', 'PAGO' => FiscalSituation::UpToDate,
            'PENDENTE', 'A_VENCER', 'VENCIDO', 'PENDING' => FiscalSituation::Pending,
            'INCONCLUSIVO', 'UNKNOWN', '' => FiscalSituation::Unknown,
            'NAO_APLICAVEL' => FiscalSituation::NotApplicable,
            default => FiscalSituation::Unknown,
        };

        return new self(
            version: self::VERSION,
            competence: $competence,
            status: $status,
            dasNumber: $das !== '' ? $das : null,
            dueDate: $due !== '' ? $due : null,
            amount: $amount,
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
            'dto' => 'pgmei',
            'dto_version' => $this->version,
            'competence' => $this->competence,
            'status' => $this->status,
            'das_number' => $this->dasNumber,
            'due_date' => $this->dueDate,
            'amount' => $this->amount,
            'situation' => $this->situation->value,
            'regime_family' => 'MEI',
            // Emissão/extrato ≠ confirmação de pagamento
            'payment_inferred' => false,
        ];
    }
}
