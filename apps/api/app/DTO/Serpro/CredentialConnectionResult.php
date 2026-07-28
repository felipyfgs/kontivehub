<?php

namespace App\DTO\Serpro;

use App\Models\SerproCredentialConnectionEvidence;
use App\Models\SerproCredentialVersion;

final readonly class CredentialConnectionResult
{
    public function __construct(
        public SerproCredentialConnectionEvidence $evidence,
        public SerproCredentialVersion $credentialVersion,
    ) {}
}
