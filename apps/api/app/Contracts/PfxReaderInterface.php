<?php

namespace App\Contracts;

use Carbon\CarbonImmutable;

interface PfxReaderInterface
{
    /**
     * @return array{
     *   pfx: string,
     *   password: string,
     *   subject_name: string,
     *   cnpj: string,
     *   fingerprint_sha256: string,
     *   valid_from: CarbonImmutable,
     *   valid_to: CarbonImmutable
     * }
     */
    public function read(string $pfxBinary, string $password): array;
}
