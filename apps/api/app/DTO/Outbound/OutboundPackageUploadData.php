<?php

namespace App\DTO\Outbound;

use Illuminate\Http\UploadedFile;

final readonly class OutboundPackageUploadData
{
    /** @param list<UploadedFile> $files */
    public function __construct(
        public array $files,
    ) {}
}
