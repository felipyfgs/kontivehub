<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTO\Esocial\EsocialBxHttpResponse;

interface EsocialBxCurlRuntime
{
    /** @param array<int, mixed> $options */
    public function execute(string $endpoint, array $options): EsocialBxHttpResponse;
}
