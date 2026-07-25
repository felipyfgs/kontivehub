<?php

namespace App\DTO\Mailbox;

/**
 * Resultado de listagem da Caixa Postal (sanitizado + itens).
 */
final readonly class CaixaPostalListResult
{
    /**
     * @param  list<array{
     *   external_id:string,
     *   category_code?:string|null,
     *   category_label?:string|null,
     *   sender_code?:string|null,
     *   sender_label?:string|null,
     *   subject?:string|null,
     *   received_at?:string|null,
     *   due_at?:string|null,
     *   severity_hint?:string|null,
     *   official_read?:bool|null,
     *   has_attachment?:bool
     * }>  $items
     * @param  array<string, mixed>  $rawMeta  metadados sanitizados (sem corpo)
     */
    public function __construct(
        public bool $success,
        public array $items = [],
        public ?int $officialUnreadCount = null,
        public bool $simulated = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawMeta = [],
        public string $sourceVersion = '1.0',
    ) {}
}
