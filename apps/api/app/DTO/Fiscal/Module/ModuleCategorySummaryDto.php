<?php

namespace App\DTO\Fiscal\Module;

/** Categoria do módulo com contagem de vínculos ativos no escopo. */
final readonly class ModuleCategorySummaryDto
{
    public function __construct(
        public int $id,
        public string $code,
        public string $name,
        public ?string $defaultCoverage,
        public int $linkedClients = 0,
    ) {}

    /**
     * @return array{
     *     id: int,
     *     code: string,
     *     name: string,
     *     default_coverage: string|null,
     *     linked_clients: int
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'default_coverage' => $this->defaultCoverage,
            'linked_clients' => $this->linkedClients,
        ];
    }
}
