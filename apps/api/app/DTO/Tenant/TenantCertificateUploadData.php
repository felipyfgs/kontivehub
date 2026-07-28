<?php

namespace App\DTO\Tenant;

final class TenantCertificateUploadData
{
    public function __construct(
        public string $filePath,
        private string $password,
        public int $actorUserId,
    ) {}

    public function takePassword(): string
    {
        $password = $this->password;
        $this->password = '';

        return $password;
    }
}
