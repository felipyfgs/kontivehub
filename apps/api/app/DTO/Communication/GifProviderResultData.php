<?php

namespace App\DTO\Communication;

final readonly class GifProviderResultData
{
    public function __construct(
        public string $providerId,
        public string $title,
        public string $previewUrl,
        public string $mediaUrl,
    ) {}
}
