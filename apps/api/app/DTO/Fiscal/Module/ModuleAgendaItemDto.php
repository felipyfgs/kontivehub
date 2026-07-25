<?php

namespace App\DTO\Fiscal\Module;

/** Próximo item de agenda (prazo / ação) sanitizado. */
final readonly class ModuleAgendaItemDto
{
    public function __construct(
        public ?int $clientId,
        public ?string $label,
        public ?string $dueAt,
        public ?string $situation,
        public ?string $href = null,
    ) {}

    /**
     * @return array{
     *     client_id: int|null,
     *     label: string|null,
     *     due_at: string|null,
     *     situation: string|null,
     *     href: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'client_id' => $this->clientId,
            'label' => $this->label,
            'due_at' => $this->dueAt,
            'situation' => $this->situation,
            'href' => $this->href,
        ];
    }
}
