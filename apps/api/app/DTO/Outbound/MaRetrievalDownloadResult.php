<?php

namespace App\DTO\Outbound;

final readonly class MaRetrievalDownloadResult
{
    /**
     * @param  list<array{filename: string, bytes: string}>  $files
     */
    public function __construct(
        public bool $success,
        public array $files = [],
        public ?string $message = null,
    ) {}
}
