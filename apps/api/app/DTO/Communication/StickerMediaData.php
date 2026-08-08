<?php

namespace App\DTO\Communication;

final readonly class StickerMediaData
{
    public function __construct(
        public int $sizeBytes,
        public string $sha256,
        public int $width,
        public int $height,
        public bool $animated,
        public string $mime = 'image/webp',
    ) {}
}
