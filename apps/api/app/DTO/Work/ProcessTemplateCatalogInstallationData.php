<?php

namespace App\DTO\Work;

final readonly class ProcessTemplateCatalogInstallationData
{
    public function __construct(
        public ?string $name,
        public ?int $defaultDepartmentId,
    ) {}
}
