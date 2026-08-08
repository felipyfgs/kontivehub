<?php

namespace App\Contracts;

use App\DTO\Communication\GifProviderResultData;

interface GifSearchProvider
{
    /** @return list<GifProviderResultData> */
    public function search(string $query, int $limit): array;
}
