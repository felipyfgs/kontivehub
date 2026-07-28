<?php

namespace App\DTO\Outbound;

use App\Enums\OutboundFiscalModel;
use App\Enums\OutboundUrgencyBand;

final readonly class OutboundPendingFilters
{
    public function __construct(
        public ?string $competence,
        public ?OutboundUrgencyBand $urgencyBand,
        public ?OutboundFiscalModel $model,
        public ?string $rootCnpjPrefix,
        public ?int $clientId,
        public ?string $source,
        public int $perPage,
        public string $sort,
        public string $direction,
    ) {}
}
