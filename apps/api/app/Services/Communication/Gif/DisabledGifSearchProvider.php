<?php

namespace App\Services\Communication\Gif;

use App\Contracts\GifSearchProvider;
use RuntimeException;

final class DisabledGifSearchProvider implements GifSearchProvider
{
    public function search(string $query, int $limit): array
    {
        throw new RuntimeException('GIF_PROVIDER_DISABLED');
    }
}
