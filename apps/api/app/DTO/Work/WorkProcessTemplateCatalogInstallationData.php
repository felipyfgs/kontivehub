<?php

namespace App\DTO\Work;

final readonly class WorkProcessTemplateCatalogInstallationData
{
    public function __construct(
        public ?string $name,
        public ?int $defaultDepartmentId,
    ) {}
}
