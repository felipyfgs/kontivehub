<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Models\FiscalSnapshot;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class SimplesMeiSnapshotPageData
{
    /** @param LengthAwarePaginator<int, FiscalSnapshot> $page */
    public function __construct(
        public LengthAwarePaginator $page,
    ) {}
}
