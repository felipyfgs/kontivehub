<?php

namespace App\DTO\Fiscal\Module;

use App\DTO\Fiscal\FiscalDocumentDescriptorDto;
use App\Enums\FiscalDataOrigin;
use App\Enums\FiscalModuleKey;

/**
 * Linha discriminada da carteira por módulo.
 * O bloco `detail` carrega campos específicos do module_key (sem payload genérico livre).
 */
final readonly class ModuleClientRowDto
{
    /**
     * @param  array<string, mixed>  $detail
     * @param  array<string, string|null>  $links
     */
    public function __construct(
        public FiscalModuleKey $moduleKey,
        public int $clientId,
        public string $legalName,
        public ?string $displayName,
        public ?string $cnpj,
        public string $cnpjMasked,
        public string $rootCnpjMasked,
        public ?string $competence,
        public string $situation,
        public string $coverage,
        public FiscalDataOrigin $dataOrigin,
        public ?string $lastConsultedAt,
        public ?string $nextDeadlineAt,
        public ?string $nextAction,
        public array $detail = [],
        public array $links = [],
        public ?FiscalDocumentDescriptorDto $document = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'module_key' => $this->moduleKey->value,
            'client_id' => $this->clientId,
            'legal_name' => $this->legalName,
            'display_name' => $this->displayName,
            'name' => ($this->displayName !== null && trim($this->displayName) !== '')
                ? $this->displayName
                : $this->legalName,
            'cnpj' => $this->cnpj,
            'cnpj_masked' => $this->cnpjMasked,
            'root_cnpj_masked' => $this->rootCnpjMasked,
            'competence' => $this->competence,
            'situation' => $this->situation,
            'coverage' => $this->coverage,
            'data_origin' => $this->dataOrigin->value,
            'last_consulted_at' => $this->lastConsultedAt,
            'next_deadline_at' => $this->nextDeadlineAt,
            'next_action' => $this->nextAction,
            'detail' => $this->detail,
            'links' => $this->links,
            'document' => $this->document?->toArray(),
        ];
    }
}
