<?php

namespace App\DTO\Fiscal\Monitoring;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class TaxGuidePageData
{
    /**
     * @param  LengthAwarePaginator<int, array<string, mixed>>  $page
     * @param  array<string, int>  $paymentCounters
     */
    public function __construct(
        public LengthAwarePaginator $page,
        public array $paymentCounters,
    ) {}
}
