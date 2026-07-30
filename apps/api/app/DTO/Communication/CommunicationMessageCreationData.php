<?php

namespace App\DTO\Communication;

use App\Enums\Communication\MessageKind;

final readonly class CommunicationMessageCreationData
{
    /**
     * @param  array<string, mixed>  $richPayload
     */
    public function __construct(
        public string $body,
        public bool $internalNote,
        public ?MessageKind $requestedKind,
        public bool $ptt,
        public bool $gif,
        public array $richPayload,
        public ?int $replyToMessageId,
        public ?string $idempotencyKey,
        public ?CommunicationMessageUploadData $upload,
        public ?int $receiptMessageId = null,
        public bool $outboundInitiation = false,
    ) {}
}
