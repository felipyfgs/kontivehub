<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class DeclarationProjectionFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
        public ?string $obligationCode,
        public ?string $moduleKey,
        public ?string $periodKey,
        public ?int $periodYear,
        public ?int $periodMonth,
        public ?string $applicability,
        public ?string $situation,
        public ?string $deliveryStatus,
        public ?bool $isOpen,
        public ?int $competenceId,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'obligation_code' => $this->obligationCode,
            'module_key' => $this->moduleKey,
            'period_key' => $this->periodKey,
            'period_year' => $this->periodYear,
            'period_month' => $this->periodMonth,
            'applicability' => $this->applicability,
            'situation' => $this->situation,
            'delivery_status' => $this->deliveryStatus,
            'is_open' => $this->isOpen,
            'competence_id' => $this->competenceId,
            'per_page' => $this->perPage,
        ];
    }
}
