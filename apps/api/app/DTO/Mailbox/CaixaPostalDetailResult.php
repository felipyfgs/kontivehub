<?php

namespace App\DTO\Mailbox;

/**
 * Detalhe de mensagem + corpo/anexos (bytes só em memória até o cofre).
 */
final readonly class CaixaPostalDetailResult
{
    /**
     * @param  list<array{
     *   external_id?:string|null,
     *   filename?:string|null,
     *   content_type?:string|null,
     *   bytes:string
     * }>  $attachments
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public bool $success,
        public string $externalId,
        public ?string $bodyBytes = null,
        public string $bodyContentType = 'text/plain',
        public array $attachments = [],
        public ?string $categoryCode = null,
        public ?string $categoryLabel = null,
        public ?string $senderCode = null,
        public ?string $senderLabel = null,
        public ?string $subject = null,
        public ?string $receivedAt = null,
        public ?string $dueAt = null,
        public ?string $severityHint = null,
        public ?bool $officialRead = null,
        public bool $simulated = false,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $meta = [],
        public string $sourceVersion = '1.0',
    ) {}
}
